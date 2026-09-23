<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Command;

use Bridge\Bot;
use Bridge\Room;
use Bridge\Support\CommandSync;
use Bridge\Support\Format;
use Bridge\Support\Permissions;
use Discord\Builders\CommandBuilder;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option as DiscordOption;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Registers actions that carry a {@see Slash} spec as Discord slash commands.
 *
 * The surface that reuses the most: an action's handler is not touched at all.
 * Discord's typed options are rendered back into exactly the text the prefix
 * form would have produced — a channel picker becomes `<#id>` — so
 * `/twitch link target:x` and `!twitch link x` run the same code down to the
 * argument indices.
 *
 * ## The shape of the tree
 *
 * One command per qualifier, and the action's group decides how deep it sits:
 *
 * ```
 * /twitch link                    (no group)
 * /twitch channel title           (group: channel)
 * ```
 *
 * Discord caps a command at 25 options and allows exactly one level of
 * sub-command group, which is the whole reason groups exist — a connector with
 * thirty commands does not fit flat. What it does *not* do is let the
 * qualifier be dropped; a chat surface does that, this one does not.
 *
 * Slash commands matter beyond convenience. Message Content is a privileged
 * intent; a server that has not granted it gets no prefix commands at all, but
 * slash commands keep working, because Discord delivers the arguments rather
 * than the bot reading them out of a message.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class SlashAdapter
{
    /** Discord's cap on options — sub-commands and groups included. */
    public const MAX_OPTIONS = 25;

    /** And on a description. */
    public const MAX_DESCRIPTION = 100;

    public function __construct(
        private readonly Bot $bot,
        private readonly ActionRegistry $registry,
    ) {
    }

    /** Defines the commands with Discord, and starts listening for them. */
    public function register(): void
    {
        $tree = $this->tree();

        if ($tree === []) {
            return;
        }

        foreach ($tree as $qualifier => $actions) {
            $this->bot->declareCommand($qualifier);

            foreach ($actions as $action) {
                $this->bot->listenCommand(
                    $action->path(),
                    fn (Interaction $interaction) => $this->invoke($action, $interaction),
                );
            }
        }

        // Defining commands needs the application, which only exists once the
        // gateway has said who we are. Listening above is already set up, so a
        // command defined on an earlier run still works — only new definitions
        // are lost, and the log says so.
        if ($this->bot->application === null) {
            $this->bot->getLogger()->error('[slash] no application yet, so no commands were defined');

            return;
        }

        $this->bot->application->commands->freshen()->then(
            fn (GlobalCommandRepository $repo) => $this->define($tree, $repo),
            fn (\Throwable $e) => $this->bot->getLogger()->error(
                '[slash] could not read existing commands, so none were defined: ' . $e->getMessage(),
            ),
        );
    }

    /**
     * Every slash-capable action, grouped by the command it belongs under.
     *
     * @return array<string, list<Action>>
     */
    private function tree(): array
    {
        $tree = [];

        foreach ($this->registry->forSurface(Surface::discord()) as $action) {
            if ($action->slash === null) {
                continue;
            }

            $tree[$action->qualifier][] = $action;
        }

        return $tree;
    }

    /**
     * @param array<string, list<Action>> $tree
     */
    private function define(array $tree, GlobalCommandRepository $repo): void
    {
        $written = 0;

        foreach ($tree as $qualifier => $actions) {
            if ($this->publish((string) $qualifier, $actions, $repo)) {
                ++$written;
            }
        }

        $this->bot->getLogger()->info(sprintf(
            '[slash] %d command(s) over %d action(s): %d unchanged, %d written',
            count($tree),
            array_sum(array_map('count', $tree)),
            count($tree) - $written,
            $written,
        ));
    }

    /**
     * Creates the command, updates it when this build defines something
     * different, and leaves it alone otherwise.
     *
     * Neither extreme works. Skipping whatever is already there means a command
     * keeps the shape it had the first time it was published — add a
     * sub-command, restart, and it is routed in code but never offered by
     * Discord, so the handler cannot be reached. Upserting on every boot
     * reaches Discord but costs one rate-limited write per command per restart
     * to republish definitions that did not change, and a global command takes
     * up to an hour to propagate, so rewriting it restarts that clock for
     * nothing.
     *
     * {@see CommandSync} tells the two apart, leniently enough that the fields
     * Discord adds on the way back — `id`, `version`, defaults it fills in, key
     * order — do not read as a change.
     *
     * @param list<Action> $actions
     *
     * @return bool Whether a write was issued.
     */
    private function publish(string $qualifier, array $actions, GlobalCommandRepository $repo): bool
    {
        $builder = $this->build($qualifier, $actions);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) json_encode($builder), true) ?? [];

        $published = $repo->get('name', $qualifier);

        if ($published === null) {
            $repo->save($builder->create($repo), 'bridge command definition')->then(
                fn () => $this->bot->getLogger()->info('[slash] registered /' . $qualifier),
                fn (\Throwable $e) => $this->bot->getLogger()->error(
                    '[slash] could not define /' . $qualifier . ': ' . $e->getMessage(),
                ),
            );

            return true;
        }

        if (! CommandSync::differs($published->jsonSerialize(), $payload)) {
            return false;
        }

        $published->fill($payload);

        $repo->save($published, 'definition changed')->then(
            fn () => $this->bot->getLogger()->info('[slash] updated /' . $qualifier),
            fn (\Throwable $e) => $this->bot->getLogger()->error(
                '[slash] could not update /' . $qualifier . ': ' . $e->getMessage(),
            ),
        );

        return true;
    }

    /**
     * Assembles one qualifier's actions into a single command definition.
     *
     * @param list<Action> $actions
     */
    private function build(string $qualifier, array $actions): CommandBuilder
    {
        $builder = CommandBuilder::new()
            ->setType(Command::CHAT_INPUT)
            ->setName($qualifier)
            ->setDescription($this->describeQualifier($qualifier, $actions))
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL);

        // Anything above Everyone acts on a server's configuration or somebody
        // else's room, and has no meaning in a DM.
        if ($this->lowestAccess($actions) !== Access::Everyone) {
            $builder->setContext([Interaction::CONTEXT_TYPE_GUILD]);
        }

        $groups = [];
        $options = [];

        foreach ($actions as $action) {
            $leaf = $this->subCommand($action);

            if ($action->group === null) {
                $options[] = $leaf;

                continue;
            }

            $groups[$action->group] ??= (new DiscordOption($this->bot))
                ->setType(DiscordOption::SUB_COMMAND_GROUP)
                ->setName($action->group)
                ->setDescription($this->clampDescription(sprintf('%s commands.', ucfirst($action->group))));

            $groups[$action->group]->addOption($leaf);
        }

        foreach ([...$options, ...array_values($groups)] as $index => $option) {
            if ($index >= self::MAX_OPTIONS) {
                // Discord rejects the whole command rather than the extra one,
                // which would take the connector's entire tree down.
                $this->bot->getLogger()->error(sprintf(
                    '[slash] /%s declares more than %d options; the rest were dropped. Group some of them.',
                    $qualifier,
                    self::MAX_OPTIONS,
                ));

                break;
            }

            $builder->addOption($option);
        }

        return $builder;
    }

    private function subCommand(Action $action): DiscordOption
    {
        $option = (new DiscordOption($this->bot))
            ->setType(DiscordOption::SUB_COMMAND)
            ->setName($action->name)
            ->setDescription($this->describe($action));

        foreach ($action->slash?->options ?? [] as $child) {
            $option->addOption($this->option($child));
        }

        return $option;
    }

    private function option(SlashOption $option): DiscordOption
    {
        return (new DiscordOption($this->bot))
            ->setType($option->type)
            ->setName($option->name)
            ->setDescription($this->clampDescription($option->description))
            ->setRequired($option->required);
    }

    /** @param list<Action> $actions */
    private function describeQualifier(string $qualifier, array $actions): string
    {
        $connector = $this->bot->connector($qualifier);
        $label = $connector?->label() ?? ucfirst($qualifier);

        return $this->clampDescription(sprintf('Bridge this server with %s, and drive it from here.', $label));
    }

    /** Discord caps a description at 100 characters and rejects an empty one. */
    private function describe(Action $action): string
    {
        $description = $action->description !== '' ? $action->description : 'No description provided.';

        if ($action->access !== Access::Everyone) {
            $suffix = ' (' . $action->access->label() . ' only)';

            if (mb_strlen($description . $suffix) <= self::MAX_DESCRIPTION) {
                $description .= $suffix;
            }
        }

        return $this->clampDescription($description);
    }

    private function clampDescription(string $description): string
    {
        return mb_substr($description === '' ? 'No description provided.' : $description, 0, self::MAX_DESCRIPTION);
    }

    /** @param list<Action> $actions */
    private function lowestAccess(array $actions): Access
    {
        $lowest = Access::Operator;

        foreach ($actions as $action) {
            if ($action->access->value < $lowest->value) {
                $lowest = $action->access;
            }
        }

        return $lowest;
    }

    // ── Dispatch ───────────────────────────────────────────────────────

    private function invoke(Action $action, Interaction $interaction): void
    {
        $access = Permissions::accessForInteraction($interaction, $this->bot->getConfig()->discordOwnerId);

        if (! $access->satisfies($action->access)) {
            $this->bot->getLogger()->info(sprintf(
                '[slash] denied /%s for user %s in guild %s (needs %s, has %s, permissions=%s)',
                $action->qualified(),
                (string) ($interaction->user->id ?? '?'),
                (string) ($interaction->guild_id ?? 'dm'),
                $action->access->label(),
                $access->label(),
                Permissions::bitsFromInteraction($interaction) ?? 'null',
            ));

            $interaction->respondWithMessage(
                $this->builder(sprintf('That command is limited to %s.', $action->access->label())),
                true,
            )->then(null, fn (\Throwable $e) => $this->logFailure($action, $e));

            return;
        }

        $wait = $this->bot->cooldowns()->claim($action, 'discord:' . (string) ($interaction->user->id ?? ''));

        if ($wait > 0) {
            // An interaction must be answered or Discord shows it as failed, so
            // unlike a chat this one says why rather than going quiet.
            $interaction->respondWithMessage(
                $this->builder(sprintf('Slow down — try that again in %ds.', $wait)),
                true,
            )->then(null, fn (\Throwable $e) => $this->logFailure($action, $e));

            return;
        }

        $spec = $action->slash;
        \assert($spec !== null);

        $ephemeral = $spec->ephemeral || $action->sensitive;
        $arguments = $this->argumentsFor($action, $interaction);

        // Defer first. Discord discards an interaction that is not acknowledged
        // within three seconds, and these handlers make network calls, so
        // nothing guarantees the reply arrives inside that window.
        $interaction->acknowledgeWithResponse($ephemeral)->then(
            fn () => $this->contextFor($action, $interaction, $access, $arguments, ! $ephemeral)->then(
                fn (Context $context) => $this->settle($action->run($context, $arguments))->then(
                    fn (string|MessageBuilder|null $reply) => $this->reply($interaction, $reply ?? 'Done.'),
                    fn (\Throwable $e) => $this->fail($interaction, $action, $e),
                ),
                fn (\Throwable $e) => $this->fail($interaction, $action, $e),
            ),
            fn (\Throwable $e) => $this->logFailure($action, $e),
        );
    }

    /**
     * Turns an interaction's typed options into the arguments a prefix handler
     * expects.
     *
     * Each value is recorded twice: by name, and positionally in declaration
     * order. That is what lets one handler read `get(0)` and another
     * `named('target')` against the same invocation.
     *
     * Positional recording stops at the first option the user omitted. Carrying
     * on would shift every later value down an index, so a handler reading
     * `get(1)` would silently receive what was meant for `get(2)` — the kind of
     * bug that bans the wrong person.
     */
    private function argumentsFor(Action $action, Interaction $interaction): Arguments
    {
        $supplied = $this->leafOptions($action, $interaction);
        $positional = [];
        $named = [];
        $contiguous = true;

        foreach ($action->slash?->options ?? [] as $option) {
            $raw = $supplied?->get('name', $option->name)?->value ?? null;

            if ($raw === null) {
                $contiguous = false;

                continue;
            }

            $rendered = $option->render($raw);
            $named[$option->name] = $rendered;

            if ($contiguous) {
                $positional[] = $rendered;
            }
        }

        return Arguments::fromParts($positional, $named);
    }

    /**
     * Walks the interaction down to the invoked leaf's own options.
     *
     * Discord nests these the same way the command is declared: a group holds a
     * sub-command, which holds the values. Reading the top level instead would
     * find the sub-command's *name* where a value was expected.
     */
    private function leafOptions(Action $action, Interaction $interaction): mixed
    {
        $options = $interaction->data?->options ?? null;

        // path() is [qualifier, (group,) name]; the qualifier is the command
        // itself, so everything after it is a level to descend.
        foreach (array_slice($action->path(), 1) as $step) {
            $options = $options?->get('name', $step)?->options ?? null;
        }

        return $options;
    }

    /**
     * Builds the invocation context, resolving which room this Discord channel
     * acts on. Mirrors {@see DiscordAdapter::contextFor()}.
     *
     * @return PromiseInterface<Context>
     */
    private function contextFor(
        Action $action,
        Interaction $interaction,
        Access $access,
        Arguments $arguments,
        bool $isPublic,
    ): PromiseInterface {
        $connector = $this->bot->connector($action->qualifier);

        $base = new Context(
            $this->bot,
            Surface::discord(),
            $access,
            (string) ($interaction->user->global_name ?? $interaction->user->username ?? 'someone'),
            (string) ($interaction->user->id ?? ''),
            $connector?->name(),
            null,
            null,
            $isPublic,
            $interaction,
        );

        if ($connector === null) {
            return resolve($base);
        }

        // Always the room this channel is bridged to. Discord only accepts the
        // options a command declares, and the only `target` declared is
        // `link`'s, which is the room to bridge with — that action's own
        // argument, not a room to act on. There is deliberately no way to
        // point a slash command at a room this server has not bridged.
        $target = $this->bot->getStore()->links($connector->name())->targetFor((string) ($interaction->channel_id ?? ''));

        if ($target === null || $target === '') {
            return resolve($base);
        }

        return $connector->resolve($target)->then(
            static fn (?Room $room): Context => $base->withTarget($target, $room?->apiId()),
            static fn (): Context => $base->withTarget($target),
        );
    }

    /**
     * Normalises a handler's return into a promise of what to send.
     *
     * A handler may answer with a string, or with a {@see MessageBuilder} when
     * a sentence will not do — a Components v2 panel with buttons that stay
     * live, most obviously. A builder only renders here, so an action that
     * returns one must declare itself Discord-only; a chat has nothing to do
     * with it.
     *
     * @return PromiseInterface<string|MessageBuilder|null>
     */
    private function settle(mixed $result): PromiseInterface
    {
        if ($result instanceof PromiseInterface) {
            return $result->then(static fn ($value) => self::rendered($value));
        }

        return resolve(self::rendered($result));
    }

    private static function rendered(mixed $value): string|MessageBuilder|null
    {
        return $value === null || $value instanceof MessageBuilder ? $value : (string) $value;
    }

    private function reply(Interaction $interaction, string|MessageBuilder $reply): void
    {
        $message = $reply instanceof MessageBuilder ? $reply : $this->builder($reply);

        $interaction->updateOriginalResponse($message)->then(
            null,
            fn (\Throwable $e) => $this->bot->getLogger()->debug('[slash] could not reply: ' . $e->getMessage()),
        );
    }

    private function fail(Interaction $interaction, Action $action, \Throwable $e): void
    {
        if ($e instanceof ActionError) {
            $this->reply($interaction, $e->getMessage());

            return;
        }

        $this->logFailure($action, $e);
        $this->reply($interaction, 'That did not work. The details are in the bot log.');
    }

    private function logFailure(Action $action, \Throwable $e): void
    {
        // Anything that is not an ActionError may carry internals — a URL with
        // a token in the query string, a file path — so it is logged in full
        // and summarised in chat.
        $this->bot->getLogger()->error(
            sprintf('[slash] /%s failed: %s', $action->qualified(), $e->getMessage()),
            ['exception' => $e],
        );
    }

    /** Every outbound message: clamped, and pinging nobody. */
    private function builder(string $text): MessageBuilder
    {
        return MessageBuilder::new()
            ->setContent(Format::clamp($text, Surface::discord()))
            ->setAllowedMentions(['parse' => []]);
    }
}

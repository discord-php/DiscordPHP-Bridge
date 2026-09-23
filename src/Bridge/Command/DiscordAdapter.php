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
use Bridge\Support\Format;
use Bridge\Support\Permissions;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Registers the action catalogue into DiscordPHP's `MessageCommandClient`, as
 * prefix commands.
 *
 * Each qualifier becomes one top-level command with a sub-command per action,
 * so `!twitch title` and `!telegram send` are what people type — the group is
 * dropped, because a chat has no 25-option cap to work around, but the
 * qualifier never is. Typing the qualifier alone lists what is under it.
 *
 * Everything platform-specific about the *Discord* side lives here: reading a
 * member's permissions, working out which room a Discord channel stands for,
 * and getting a reply back out without pinging the server.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class DiscordAdapter
{
    /** @var \Closure(Message, string): ?Access */
    private readonly \Closure $rankIn;

    /**
     * @param (callable(Message, string): ?Access)|null $rankIn The author's rung in a
     *        channel of the same server, or `null` when they cannot see it. Defaults
     *        to reading their permissions there; injectable for tests.
     */
    public function __construct(
        private readonly Bot $bot,
        private readonly ActionRegistry $registry,
        ?callable $rankIn = null,
    ) {
        $this->rankIn = \Closure::fromCallable($rankIn ?? function (Message $message, string $channelId): ?Access {
            $channel = $this->bot->getChannel($channelId);

            return $channel === null
                ? null
                : Permissions::accessIn($message, $channel, $this->bot->getConfig()->discordOwnerId);
        });
    }

    /**
     * Wires every Discord-available action into the command client.
     *
     * One command per qualifier, and the rest of the line is resolved by
     * {@see ActionRegistry::resolve()} rather than by registering each action as
     * a sub-command. That is what lets both forms work — `!twitch title` as a
     * chat would type it and `!twitch channel title` as the slash menu shows
     * it — and it keeps aliases and cooldowns on the same path as every other
     * surface instead of in DiscordPHP's own copy of them.
     */
    public function register(): void
    {
        foreach (array_keys($this->byQualifier()) as $qualifier) {
            $this->bot->registerCommand(
                $qualifier,
                fn (Message $message, array $args) => $this->route($qualifier, $message, $args),
                [
                    'description' => sprintf('Commands for %s.', $qualifier),
                    'usage' => '<command>',
                ],
            );
        }
    }

    /** Finds the action a line names, or lists what is under the qualifier. */
    private function route(string $qualifier, Message $message, array $args): void
    {
        [$action, $rest] = $this->registry->resolve([$qualifier, ...array_map('strval', $args)]);

        if ($action === null || ! $action->availableOn(Surface::discord())) {
            $this->listUnder($message, $qualifier);

            return;
        }

        $this->invoke($action, $message, $rest);
    }

    /**
     * Every Discord-available action, grouped by the command it lives under.
     *
     * @return array<string, list<Action>>
     */
    private function byQualifier(): array
    {
        $grouped = [];

        foreach ($this->registry->forSurface(Surface::discord()) as $action) {
            $grouped[$action->qualifier][] = $action;
        }

        return $grouped;
    }

    /** What somebody who typed a bare qualifier probably wanted. */
    private function listUnder(Message $message, string $qualifier): void
    {
        $access = Permissions::accessFor($message, $this->bot->getConfig()->discordOwnerId);
        $names = [];

        foreach ($this->registry->forQualifier($qualifier) as $action) {
            if ($access->satisfies($action->access)) {
                $names[] = '`' . $action->name . '`';
            }
        }

        $this->say($message, Format::listing(
            Surface::discord(),
            $names,
            sprintf('Commands under `%s%s`', $this->bot->getConfig()->discordPrefix, $qualifier),
            'nothing you can run.',
        ));
    }

    private function invoke(Action $action, Message $message, array $args): void
    {
        $access = Permissions::accessFor($message, $this->bot->getConfig()->discordOwnerId);

        if (! $access->satisfies($action->access)) {
            $this->say($message, sprintf('that command is limited to %s.', $action->access->label()));

            return;
        }

        $wait = $this->bot->cooldowns()->claim($action, 'discord:' . (string) ($message->author->id ?? ''));

        if ($wait > 0) {
            $this->say($message, sprintf('slow down — try that again in %ds.', $wait));

            return;
        }

        $arguments = Arguments::fromTokens($args);

        // A sensitive action can return a stream key or a token. There is no
        // quiet corner of a guild channel, so it is answered in a DM and the
        // channel is told only that it happened.
        $private = $action->sensitive && ($message->guild_id !== null);

        $this->contextFor($action, $message, $access, $arguments, ! $private)->then(
            function (Context $context) use ($action, $arguments, $message, $private): void {
                $this->settle($action->run($context, $arguments))->then(
                    fn (string|MessageBuilder|null $reply) => $reply === null ? null : $this->deliver($message, $reply, $private),
                    fn (\Throwable $e) => $this->fail($message, $action, $e),
                );
            },
            fn (\Throwable $e) => $this->fail($message, $action, $e),
        );
    }

    /**
     * Builds the invocation context, resolving which room this Discord channel
     * acts on.
     *
     * The action's own qualifier picks the connector, which is what makes this
     * work with more than one installed: `twitch title` acts on the Twitch room
     * this channel is bridged to even when the same channel is also bridged to
     * a Telegram group.
     *
     * An explicit `channel=` or `target=` wins over the bridge, so a server
     * that bridges several rooms can reach any of them from one channel — but
     * only rooms *this server* has bridged, unless the operator is asking.
     *
     * @return PromiseInterface<Context>
     */
    private function contextFor(
        Action $action,
        Message $message,
        Access $access,
        Arguments $arguments,
        bool $isPublic,
    ): PromiseInterface {
        $connector = $this->bot->connector($action->qualifier);

        $base = new Context(
            $this->bot,
            Surface::discord(),
            $access,
            (string) ($message->author->displayname ?? $message->author->username ?? 'someone'),
            (string) ($message->author->id ?? ''),
            $connector?->name(),
            null,
            null,
            $isPublic,
            $message,
        );

        if ($connector === null) {
            return resolve($base);
        }

        $links = $this->bot->getStore()->links($connector->name());
        $override = $this->roomOverride($action, $arguments);

        if ($override === null) {
            $target = $links->targetFor((string) $message->channel_id);

            if ($target === null || $target === '') {
                return resolve($base);
            }

            return $connector->resolve($target)->then(
                static fn (?Room $room): Context => $base->withTarget($target, $room?->apiId()),
                // A lookup that failed still leaves a usable target; the action
                // will fail on its own terms rather than on a name resolution.
                static fn (): Context => $base->withTarget($target),
            );
        }

        // Naming a room is a convenience for a server that bridges more than
        // one — not a way around bridging. Without this check anyone could
        // point `ban`, `raid` or `send` at any room the bot can reach, in any
        // server the bot is in, and the link table would guard nothing.
        $refused = new ActionError(sprintf(
            '`%s` is not a %s room this server has bridged, so it cannot be acted on from here.',
            mb_substr(trim($override), 0, 60),
            $connector->label(),
        ));
        $named = $connector->normalise($override);

        if ($named === null || $named === '') {
            return reject($refused);
        }

        $guildId = (string) ($message->guild_id ?? '');
        $bridgedHere = $guildId === '' ? [] : $links->forGuild($guildId);

        return $connector->resolve($named)->then(
            function (?Room $room) use ($action, $base, $named, $bridgedHere, $access, $message, $refused): Context {
                // Compared by the id the room is stored under, which need not
                // be what was typed: a Telegram `@name` is stored as its id.
                $id = $room?->id ?? $named;
                $context = $base->withTarget($id, $room?->apiId());

                if ($access === Access::Operator) {
                    return $context;
                }

                // Held to the channel the room is bridged to, not the one this
                // was typed in: rank in one channel says nothing about another,
                // and a room bridged to a channel somebody cannot open is not
                // theirs to reach from one they can.
                $best = null;

                foreach ($bridgedHere as $channelId => $target) {
                    if ((string) $target !== $id) {
                        continue;
                    }

                    $there = ($this->rankIn)($message, (string) $channelId);

                    if ($there !== null && ($best === null || $there->value > $best->value)) {
                        $best = $there;
                    }
                }

                if ($best === null) {
                    throw $refused;
                }

                // The lower of the two: the rung checked before getting here
                // was this channel's, and it must hold in both.
                $effective = $best->value < $access->value ? $best : $access;

                if (! $effective->satisfies($action->access)) {
                    throw new ActionError(sprintf(
                        'that is limited to %s in the channel `%s` is bridged to.',
                        $action->access->label(),
                        $id,
                    ));
                }

                return $context->withAccess($effective);
            },
            // Unverifiable is refused: it cannot be shown to be one of ours.
            static fn (): never => throw $refused,
        );
    }

    /**
     * The room somebody named in `channel=` or `target=`, when that is what it
     * means.
     *
     * An action that declares an option by that name owns it: `link` takes a
     * `target` because that is the room to bridge with, and `raid` a `channel`
     * because that is who to raid. Anything else is a request to act on a room
     * other than this channel's own.
     */
    private function roomOverride(Action $action, Arguments $arguments): ?string
    {
        foreach (['channel', 'target'] as $key) {
            $value = $arguments->named($key);

            if ($value !== null && ! $action->declaresOption($key)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Normalises a handler's return into a promise of what to send.
     *
     * A handler may answer with a string or with a {@see MessageBuilder}, for
     * the cases a sentence will not cover — a Components v2 panel, say. Both
     * render on Discord, which is why an action that returns a builder must
     * declare itself Discord-only.
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

    private function deliver(Message $message, string|MessageBuilder $reply, bool $private): void
    {
        if (! $private) {
            $this->say($message, $reply);

            return;
        }

        $author = $message->author;

        if ($author === null) {
            return;
        }

        $author->sendMessage($reply instanceof MessageBuilder ? $reply : $this->builder($reply))->then(
            fn () => $this->say($message, 'sent you that in a DM — it contains something that should not be posted in a channel.'),
            fn () => $this->say($message, 'that answer contains a secret and your DMs are closed, so it has not been sent.'),
        );
    }

    private function fail(Message $message, Action $action, \Throwable $e): void
    {
        if ($e instanceof ActionError) {
            $this->say($message, $e->getMessage());

            return;
        }

        // Anything else may carry internals. Log it in full; say only that it
        // broke.
        $this->bot->getLogger()->error(sprintf(
            '[discord] action %s failed: %s',
            $action->qualified(),
            $e->getMessage(),
        ), ['exception' => $e]);

        $this->say($message, 'that did not work. The details are in the bot log.');
    }

    private function say(Message $message, string|MessageBuilder $text): void
    {
        $message->reply($text instanceof MessageBuilder ? $text : $this->builder($text))->then(
            null,
            function (\Throwable $e): void {
                $this->bot->getLogger()->debug('[discord] could not reply: ' . $e->getMessage());
            },
        );
    }

    /** Every outbound message: clamped to Discord's limit, and pinging nobody. */
    private function builder(string $text): MessageBuilder
    {
        return MessageBuilder::new()
            ->setContent(Format::clamp($text, Surface::discord()))
            ->setAllowedMentions(['parse' => []]);
    }
}

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
    public function __construct(
        private readonly Bot $bot,
        private readonly ActionRegistry $registry,
    ) {
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
     * that bridges one room can still ask about another without rewiring it.
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

        $explicit = $arguments->named('channel') ?? $arguments->named('target');

        $target = $explicit !== null
            ? $connector->normalise($explicit)
            : $this->bot->getStore()->links($connector->name())->targetFor((string) $message->channel_id);

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

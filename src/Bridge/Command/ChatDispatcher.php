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
use Bridge\Connector;
use Bridge\Room;
use Discord\Builders\MessageBuilder;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Runs commands typed in a connector's own chat — Twitch chat, a Telegram group.
 *
 * Every chat surface needs the same thing: split the line, find the action,
 * check the rung, honour the cooldown, work out what it acts on, run it, say
 * the answer. Writing that once per connector is how two of them end up
 * disagreeing about a permission, so a connector only supplies what it alone
 * knows — who is asking and where, in a {@see ChatInvocation} — and a way to
 * reply.
 *
 * ## Commands that cross networks
 *
 * The point of one bot is that `!telegram send hi` typed in Twitch chat says
 * "hi" in the Telegram group. That needs care: the command belongs to Telegram
 * but was typed in a Twitch room, so the Twitch room's id means nothing to it.
 * The target is found by following the bridges through Discord instead — the
 * Twitch room is bridged to a Discord channel, which is bridged to a Telegram
 * group, and that group is the one meant.
 *
 * When that walk finds more than one — the Twitch room feeds two Discord
 * channels bridged to two different groups — nothing is guessed. The action
 * gets no target and says it could not work out which one was meant, which is
 * far better than posting into the wrong community.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class ChatDispatcher
{
    public function __construct(
        private readonly Bot $bot,
        private readonly Connector $connector,
    ) {
    }

    /**
     * Whether a line of chat is addressed to the bot.
     *
     * Only a *registered* command counts. Chat is full of `!` — "yes!!!", or
     * another bot's `!drop` — and treating all of it as a command would quietly
     * stop relaying a slice of ordinary conversation. A bare qualifier counts,
     * because the bot answers it with a list.
     */
    public function isCommand(string $text): bool
    {
        $words = $this->words($text);

        if ($words === null) {
            return false;
        }

        return $this->bot->getActions()->resolve($words)[0] !== null
            || (count($words) === 1 && $this->isQualifier($words[0]));
    }

    /**
     * Runs a line of chat if it is a command, and reports whether it was.
     *
     * @param callable(string): mixed $reply How to answer in the chat it came from.
     */
    public function dispatch(string $text, ChatInvocation $who, callable $reply): bool
    {
        $words = $this->words($text);

        if ($words === null) {
            return false;
        }

        $surface = $this->connector->surface();
        [$action, $rest] = $this->bot->getActions()->resolve($words);

        if ($action === null) {
            if (count($words) === 1 && $this->isQualifier($words[0])) {
                $reply($this->listUnder(strtolower($words[0]), $who->access));

                return true;
            }

            return false;
        }

        if (! $action->availableOn($surface)) {
            $reply(sprintf('that one only works on Discord — try /%s there.', $action->qualified()));

            return true;
        }

        if (! $who->access->satisfies($action->access)) {
            $reply(sprintf('that one is limited to %s.', $action->access->label()));

            return true;
        }

        // A sensitive answer has nowhere safe to go in a chat: it is public,
        // and a DM from a bot is unreliable where it exists at all.
        if ($action->sensitive) {
            $reply('that one can only be run from Discord — the answer must not be posted in chat.');

            return true;
        }

        // Silent when cooling down. Answering every repeat with "slow down" in
        // a busy chat doubles the noise the cooldown exists to prevent.
        if ($this->bot->cooldowns()->claim($action, $surface->name . ':' . $who->invokerId) > 0) {
            return true;
        }

        $arguments = Arguments::fromTokens($rest);

        $this->contextFor($action, $who)->then(
            fn (Context $context) => $this->settle($action->run($context, $arguments)),
        )->then(
            function (mixed $answer) use ($reply): void {
                if ($answer instanceof MessageBuilder) {
                    // Only reachable when an action returns a panel without
                    // declaring itself Discord-only, which is a bug in that
                    // action rather than something to render badly here.
                    $reply('that answer can only be shown on Discord.');

                    return;
                }

                if ($answer !== null && $answer !== '') {
                    $reply((string) $answer);
                }
            },
            fn (\Throwable $e) => $this->fail($action, $e, $reply),
        );

        return true;
    }

    /**
     * What an action typed here acts on.
     *
     * @return PromiseInterface<Context>
     */
    public function contextFor(Action $action, ChatInvocation $who): PromiseInterface
    {
        $own = $this->connector->name();
        $qualifier = $action->qualifier;
        $target = $this->bot->connector($qualifier);

        $base = new Context(
            $this->bot,
            $this->connector->surface(),
            $who->access,
            $who->invokerName,
            $who->invokerId,
            $target?->name(),
            null,
            null,
            true,
            $who->message,
        );

        if ($target === null) {
            // The core's own commands, which act on no room.
            return resolve($base);
        }

        if ($qualifier === $own) {
            return resolve($base->withTarget($who->room, $who->roomId));
        }

        $room = $this->bridgedThroughDiscord($who->room, $qualifier);

        if ($room === null) {
            return resolve($base);
        }

        return $target->resolve($room)->then(
            static fn (?Room $found): Context => $base->withTarget($room, $found?->apiId()),
            static fn (): Context => $base->withTarget($room),
        );
    }

    /**
     * The one room on `$network` that this chat's room reaches through
     * Discord, or `null` when there is none or more than one.
     */
    public function bridgedThroughDiscord(string $room, string $network): ?string
    {
        $store = $this->bot->getStore();
        $found = [];

        foreach ($store->links($this->connector->name())->discordFor($room) as $channelId) {
            $other = $store->links($network)->targetFor($channelId);

            if ($other !== null) {
                $found[$other] = true;
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    /** @return list<string>|null The words after the prefix, or `null` when there is no prefix. */
    private function words(string $text): ?array
    {
        $prefix = $this->connector->surface()->prefix;
        $text = trim($text);

        if ($prefix === '' || ! str_starts_with($text, $prefix)) {
            return null;
        }

        $words = preg_split('/\s+/', trim(substr($text, strlen($prefix)))) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));

        return $words === [] ? null : $words;
    }

    private function isQualifier(string $word): bool
    {
        return in_array(strtolower($word), $this->bot->getActions()->qualifiers(), true);
    }

    /** What somebody who typed a bare qualifier probably wanted. */
    private function listUnder(string $qualifier, Access $access): string
    {
        $surface = $this->connector->surface();
        $names = [];

        foreach ($this->bot->getActions()->forQualifier($qualifier) as $action) {
            if ($action->availableOn($surface) && $access->satisfies($action->access)) {
                $names[] = $action->name;
            }
        }

        return $names === []
            ? sprintf('nothing under %s%s you can run here.', $surface->prefix, $qualifier)
            : sprintf('%s%s: %s', $surface->prefix, $qualifier, implode(', ', $names));
    }

    /** @return PromiseInterface<mixed> */
    private function settle(mixed $result): PromiseInterface
    {
        return $result instanceof PromiseInterface ? $result : resolve($result);
    }

    /** @param callable(string): mixed $reply */
    private function fail(Action $action, \Throwable $e, callable $reply): void
    {
        if ($e instanceof ActionError) {
            $reply($e->getMessage());

            return;
        }

        // Anything else may carry internals — a URL with a token in it, a file
        // path — so it is logged in full and summarised in chat.
        $this->bot->getLogger()->error(sprintf(
            '[%s] action %s failed: %s',
            $this->connector->name(),
            $action->qualified(),
            $e->getMessage(),
        ), ['exception' => $e]);

        $reply('that did not work.');
    }
}

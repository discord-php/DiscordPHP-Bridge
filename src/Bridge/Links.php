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

namespace Bridge;

/**
 * The routing table for one connector: which Discord channel is bridged to
 * which of that platform's rooms.
 *
 * Immutable and derived from whatever {@see Store} has persisted, so routing
 * can be reasoned about — and tested — without a gateway, a socket, or a
 * config file.
 *
 * The two directions are deliberately asymmetric. A Discord channel bridges to
 * exactly one target, so `Discord → platform` is a lookup. But any number of
 * servers may follow the *same* target, so `platform → Discord` fans out to a
 * list; one message can land in many Discord channels across unrelated guilds.
 *
 * Targets are held as strings throughout, whatever the platform calls them.
 * A Twitch login is a name, a Telegram supergroup id (`-1001234567890`) is
 * larger than a 32-bit int, and an `@username` is not a number at all, so
 * comparing them as strings is the only form correct for every platform.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Links
{
    /** @var array<string, string> Discord channel id => target. */
    private array $toTarget = [];

    /** @var array<string, list<string>> target => Discord channel ids. */
    private array $toDiscord = [];

    /** @var array<string, string> Case-folded target => the target as stored. */
    private array $folded = [];

    /**
     * @param array<string, array<string, string>> $guilds guild id => [discord channel id => target]
     */
    public function __construct(private readonly array $guilds = [])
    {
        foreach ($this->guilds as $channels) {
            foreach ($channels as $channelId => $target) {
                // PHP turns a numeric string key back into an int, and both a
                // channel id and "-1001…" are numeric strings, so these have to
                // be cast back before they leave — otherwise a caller comparing
                // them strictly against the strings used everywhere else
                // silently matches nothing.
                $channelId = (string) $channelId;
                $target = (string) $target;

                $this->toTarget[$channelId] = $target;
                $this->toDiscord[$target][] = $channelId;
                $this->folded[mb_strtolower($target, 'UTF-8')] = $target;
            }
        }
    }

    /** The target a Discord channel relays to, or `null` when unbridged. */
    public function targetFor(int|string $discordChannelId): ?string
    {
        return $this->toTarget[(string) $discordChannelId] ?? null;
    }

    /**
     * Every Discord channel that should receive a message from a target —
     * possibly across several guilds.
     *
     * Matched exactly, then case-insensitively. A network that echoes a room
     * back in different case than it was configured in — IRC does — would
     * otherwise deliver nothing, which reads exactly like a bridge that was
     * never set up.
     *
     * @return list<string>
     */
    public function discordFor(int|string $target): array
    {
        $target = (string) $target;

        return $this->toDiscord[$target]
            ?? $this->toDiscord[$this->folded[mb_strtolower($target, 'UTF-8')] ?? '']
            ?? [];
    }

    /**
     * Every target the connector needs to be in, deduplicated: two guilds
     * following the same room share one membership.
     *
     * @return list<string>
     */
    public function targets(): array
    {
        $targets = array_map(strval(...), array_keys($this->toDiscord));
        sort($targets, SORT_STRING);

        return $targets;
    }

    /** Whether any Discord channel at all is bridged to this target. */
    public function isBridged(int|string $target): bool
    {
        return $this->discordFor($target) !== [];
    }

    /**
     * One guild's links.
     *
     * @return array<string, string> discord channel id => target
     */
    public function forGuild(int|string $guildId): array
    {
        $links = [];

        foreach ($this->guilds[(string) $guildId] ?? [] as $channelId => $target) {
            $links[(string) $channelId] = (string) $target;
        }

        return $links;
    }

    /**
     * Every guild that has configured at least one bridge.
     *
     * @return list<string>
     */
    public function guilds(): array
    {
        return array_map(strval(...), array_keys($this->guilds));
    }

    public function isEmpty(): bool
    {
        return $this->toTarget === [];
    }

    public function count(): int
    {
        return count($this->toTarget);
    }

    /**
     * What to join and what to leave to get from this routing table to `$next`.
     *
     * Returned as a diff rather than "just rejoin everything" so a config
     * change in one guild does not blink every other guild's bridge offline.
     *
     * @return array{join: list<string>, part: list<string>}
     */
    public function diff(self $next): array
    {
        $have = $this->targets();
        $want = $next->targets();

        return [
            'join' => array_values(array_diff($want, $have)),
            'part' => array_values(array_diff($have, $want)),
        ];
    }
}

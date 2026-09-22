<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge;

use Bridge\Support\Filesystem;
use Bridge\Support\JsonFile;
use React\Promise\PromiseInterface;

/**
 * Every bridge the bot has, for every connector, in one file.
 *
 * This is the only thing that remembers what a server configured, so losing it
 * means every admin has to run `link` again. {@see JsonFile} is what keeps that
 * from happening — atomic writes, a backup beside it, a damaged file preserved
 * rather than overwritten, and the write itself kept off the event loop. What
 * this class adds on top is the shape: bridges are grouped by connector, so
 * `twitch` and `telegram` cannot collide even when a guild bridges the same
 * Discord channel name on both.
 *
 * ```json
 * { "version": 2,
 *   "links":  { "twitch": { "<guild>": { "<channel>": "coffeescrafts" } } },
 *   "labels": { "telegram": { "-1001234567890": "My Group" } } }
 * ```
 *
 * A file written by the single-platform bots this one replaces has no
 * `version` and no connector level; it is migrated on load into whichever
 * connector the caller names, so an existing installation keeps its bridges
 * without anyone having to re-run anything.
 *
 * Entries of the wrong shape are dropped rather than loaded, and what was
 * dropped is recorded in {@see warnings()} — a hand-edited file should not be
 * able to take the bot down with a `TypeError` three layers away, nor vanish
 * silently.
 *
 * Every mutator hands back a fresh {@see Links}, which is what callers act on:
 * the store owns persistence, {@see Links} owns routing.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Store
{
    /** The shape this class writes. Anything older is migrated on load. */
    public const VERSION = 2;

    private readonly JsonFile $file;

    /** @var array{version: int, links: array<string, array<string, array<string, string>>>, labels: array<string, array<string, string>>} */
    private array $data;

    /** @var list<string> */
    private array $warnings = [];

    private bool $migrated = false;

    /**
     * @param string $legacyConnector Which connector a pre-`version` file's
     *                                bridges belong to. The bots this replaces
     *                                each had exactly one, so the file itself
     *                                cannot say.
     */
    public function __construct(
        string $path,
        ?Filesystem $filesystem = null,
        private readonly string $legacyConnector = 'twitch',
    ) {
        $this->file = new JsonFile($path, $filesystem);
        $this->data = $this->sanitize($this->file->load());
        $this->warnings = [...$this->file->warnings(), ...$this->warnings];
    }

    // ── Reading ────────────────────────────────────────────────────────

    /** One connector's routing table. Empty for a connector with no bridges. */
    public function links(string $connector): Links
    {
        return new Links($this->data['links'][$connector] ?? []);
    }

    /**
     * Every connector that has at least one bridge.
     *
     * Not the same as the connectors that are *registered*: a file may name one
     * that is not installed any more, and those bridges are kept rather than
     * quietly dropped.
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        $connectors = array_keys($this->data['links']);
        sort($connectors, SORT_STRING);

        return array_map(strval(...), $connectors);
    }

    /** How many bridges exist across every connector. */
    public function count(): int
    {
        $total = 0;

        foreach ($this->connectors() as $connector) {
            $total += $this->links($connector)->count();
        }

        return $total;
    }

    /**
     * What a target is called, when the connector has told us.
     *
     * A Telegram chat id says nothing to a human, and the name has to come from
     * an API call the bot may not be able to repeat — it is remembered so a
     * listing does not have to go and ask.
     */
    public function label(string $connector, int|string $target): ?string
    {
        return $this->data['labels'][$connector][(string) $target] ?? null;
    }

    /** Whether the file on disk was written by an older version of the bot. */
    public function migrated(): bool
    {
        return $this->migrated;
    }

    /**
     * Anything that went wrong while reading, in the order it was found. Empty
     * on a normal start.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function path(): string
    {
        return $this->file->path();
    }

    /** The backend in use, for the startup line. */
    public function filesystem(): Filesystem
    {
        return $this->file->filesystem();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    // ── Writing ────────────────────────────────────────────────────────

    /**
     * Bridges a Discord channel to a target.
     *
     * A Discord channel points at one target per connector, so linking it again
     * replaces the previous one rather than accumulating.
     */
    public function link(
        string $connector,
        int|string $guildId,
        int|string $discordChannelId,
        string $target,
        ?string $label = null,
    ): Links {
        $this->data['links'][$connector][(string) $guildId][(string) $discordChannelId] = $target;

        if ($label !== null && $label !== '') {
            $this->data['labels'][$connector][$target] = $label;
        }

        $this->save();

        return $this->links($connector);
    }

    /** Removes one Discord channel's bridge. No-op when it was not bridged. */
    public function unlink(string $connector, int|string $guildId, int|string $discordChannelId): Links
    {
        $guild = (string) $guildId;
        $channel = (string) $discordChannelId;

        if (isset($this->data['links'][$connector][$guild][$channel])) {
            unset($this->data['links'][$connector][$guild][$channel]);
            $this->prune($connector, $guild);
            $this->save();
        }

        return $this->links($connector);
    }

    /** Drops every bridge a guild has configured for one connector. */
    public function forgetGuild(string $connector, int|string $guildId): Links
    {
        if (isset($this->data['links'][$connector][(string) $guildId])) {
            unset($this->data['links'][$connector][(string) $guildId]);
            $this->prune($connector, (string) $guildId);
            $this->save();
        }

        return $this->links($connector);
    }

    /** Remembers what a target is called, so a listing can show a name. */
    public function rememberLabel(string $connector, int|string $target, string $label): void
    {
        $target = (string) $target;

        if ($label === '' || ($this->data['labels'][$connector][$target] ?? null) === $label) {
            return;
        }

        $this->data['labels'][$connector][$target] = $label;
        $this->save();
    }

    /**
     * Resolves when everything changed so far has reached the disk.
     *
     * For tests and for anything that genuinely must not continue until the
     * change is durable. Ordinary callers do not wait.
     */
    public function saved(): PromiseInterface
    {
        return $this->file->saved();
    }

    /**
     * Writes now, blocking, for shutdown.
     *
     * Once the loop stops a queued write would never run, and the change
     * somebody just made would be the one lost.
     */
    public function flush(): bool
    {
        return $this->file->flush();
    }

    // ── Internals ──────────────────────────────────────────────────────

    private function save(): void
    {
        $this->forgetUnusedLabels();
        $this->file->save($this->data);
    }

    /** Drops a guild, and then a connector, once nothing is left under it. */
    private function prune(string $connector, string $guildId): void
    {
        if (($this->data['links'][$connector][$guildId] ?? null) === []) {
            unset($this->data['links'][$connector][$guildId]);
        }

        if (($this->data['links'][$connector] ?? null) === []) {
            unset($this->data['links'][$connector]);
        }
    }

    /**
     * Forgets the name of a target nothing points at any more.
     *
     * Without this the file grows a label for every chat ever linked, and the
     * names of rooms the bot has long since left outlive the bridges to them.
     */
    private function forgetUnusedLabels(): void
    {
        foreach ($this->data['labels'] as $connector => $labels) {
            $links = $this->links((string) $connector);

            foreach ($labels as $target => $_) {
                if (! $links->isBridged((string) $target)) {
                    unset($this->data['labels'][$connector][$target]);
                }
            }

            if (($this->data['labels'][$connector] ?? null) === []) {
                unset($this->data['labels'][$connector]);
            }
        }
    }

    /**
     * Brings whatever was on disk up to the current shape, dropping what cannot
     * be understood and saying so.
     *
     * @param  array<string, mixed> $data
     * @return array{version: int, links: array<string, array<string, array<string, string>>>, labels: array<string, array<string, string>>}
     */
    private function sanitize(array $data): array
    {
        $clean = ['version' => self::VERSION, 'links' => [], 'labels' => []];
        $links = (array) ($data['links'] ?? []);

        if ($links !== [] && ! isset($data['version'])) {
            // Written by one of the single-platform bots: guild ids at the top
            // level, no connector between. Everything in it belongs to the one
            // connector that bot had.
            $links = [$this->legacyConnector => $links];
            $this->migrated = true;
        }

        foreach ($links as $connector => $guilds) {
            $connector = (string) $connector;

            if (! is_array($guilds)) {
                $this->warnings[] = sprintf('Ignored the %s bridges: they are not a list of servers.', $connector);

                continue;
            }

            foreach ($guilds as $guildId => $channels) {
                if (! is_array($channels)) {
                    $this->warnings[] = sprintf(
                        'Ignored the %s entry for server %s: it is not a list of channels.',
                        $connector,
                        (string) $guildId,
                    );

                    continue;
                }

                foreach ($channels as $channelId => $target) {
                    if (! is_scalar($target) || (string) $target === '') {
                        $this->warnings[] = sprintf(
                            'Ignored the %s bridge for channel %s in server %s: its target is not usable.',
                            $connector,
                            (string) $channelId,
                            (string) $guildId,
                        );

                        continue;
                    }

                    $clean['links'][$connector][(string) $guildId][(string) $channelId] = (string) $target;
                }
            }
        }

        foreach ((array) ($data['labels'] ?? []) as $connector => $labels) {
            foreach ((array) $labels as $target => $label) {
                if (is_scalar($label) && (string) $label !== '') {
                    $clean['labels'][(string) $connector][(string) $target] = (string) $label;
                }
            }
        }

        return $clean;
    }
}

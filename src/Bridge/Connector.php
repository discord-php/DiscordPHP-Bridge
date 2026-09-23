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

use Bridge\Command\Surface;
use Bridge\Message\Incoming;
use Bridge\Message\Outgoing;
use React\Promise\PromiseInterface;

/**
 * One network the bot bridges Discord with.
 *
 * Everything platform-specific lives behind this: a connector owns its client,
 * its socket, its authentication and its idea of what a room is, and hands the
 * core {@see Incoming} messages and {@see Room} descriptions. Nothing in the
 * core mentions Twitch or Telegram, and adding a third network is a new package
 * rather than an edit to this one.
 *
 * A connector is registered with {@see Bot::addConnector()}, which calls
 * {@see boot()} immediately and {@see start()} once Discord is ready — the
 * order matters, because listeners registered in `boot()` must already be in
 * place when the first message arrives.
 *
 * ## What a connector must not do
 *
 * **Never build its own HTTP client for Discord.** The bot has exactly one
 * `Discord\Http`, and that object is where the per-route rate-limit buckets and
 * the concurrency cap live. A second client keeps its own empty bucket table
 * and races the first into 429s against the same token — and 10,000 rejected
 * requests in ten minutes is a Cloudflare ban on the whole host. Use the parts
 * and repositories DiscordPHP hands over; they already route through it.
 *
 * Its own network is its own business: a connector is expected to pace *that*
 * side itself, which is what {@see Support\RateLimiter} is for.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface Connector
{
    /**
     * A short, lowercase, stable name — `twitch`, `telegram`.
     *
     * It is not only a label. It keys this connector's bridges in {@see Store},
     * and it is the top-level slash command the connector publishes, so
     * changing it strands both.
     */
    public function name(): string;

    /** What to call the network in front of a human: `Twitch`, `Telegram`. */
    public function label(): string;

    /** What this network's chat can take; see {@see Surface}. */
    public function surface(): Surface;

    /**
     * Builds the client and registers everything that must exist before the
     * first message arrives. Called once, when the connector is added.
     */
    public function boot(Bot $bot): void;

    /**
     * Connects. Called once Discord is ready and the bridges are known.
     *
     * Resolves once the connector can actually send and receive, and rejects
     * when it cannot. That is not a formality: the core joins rooms only after
     * this resolves, reports a network that failed rather than one that merely
     * looks quiet, and refuses to remove stale slash commands unless every
     * connector got here — pruning against a connector that never declared its
     * commands would delete them.
     *
     * @return PromiseInterface<mixed>
     */
    public function start(): PromiseInterface;

    /** Disconnects cleanly, for shutdown. */
    public function stop(): void;

    /**
     * Joins and leaves whatever the routing table now says, and reports what it
     * did.
     *
     * A diff rather than "rejoin everything", so one server's change does not
     * blink every other server's bridge offline.
     *
     * @return array{join: list<string>, part: list<string>}
     */
    public function sync(Links $links): array;

    /**
     * Says something in a room, exactly as given.
     *
     * For text this bot composed itself — a command's reply, a notice. Relayed
     * chat goes through {@see relay()} instead, which is the one that still has
     * to be rendered.
     *
     * @param array<string, mixed> $options Connector-specific extras — a reply target,
     *                                      a file to attach. Unknown keys are ignored.
     */
    public function send(string $target, string $text, array $options = []): PromiseInterface;

    /**
     * Renders a Discord message for this network and says it.
     *
     * The rendering is the connector's because the answer is: IRC cannot carry
     * a newline, Telegram's HTML mode needs exactly three characters escaped,
     * and the limits are 500 and 4096. {@see \Bridge\Support\MessageText} holds
     * the parts that are the same everywhere — resolving mentions, budgeting
     * attachment links, truncating on a character boundary — so a connector
     * writes only what is genuinely its own.
     *
     * Resolves to `null` when there was nothing worth relaying, which is not a
     * failure: an embed-only message says nothing a chat can repeat.
     *
     * @return PromiseInterface<?string> The id of what was said, when the network has them.
     */
    public function relay(string $target, Outgoing $message): PromiseInterface;

    /**
     * Registers the handler every inbound message is passed to.
     *
     * The connector is responsible for setting {@see Incoming::$own} on
     * anything the bot itself said. A bridge that repeats its own output is an
     * infinite loop that gets the account banned from both networks within
     * minutes, and only the connector can recognise its own voice.
     *
     * @param (callable(Incoming): void) $handler
     */
    public function onIncoming(callable $handler): void;

    /**
     * Looks a room up, resolving to `null` when it does not exist.
     *
     * Used by `link` before wiring anything up — a typo otherwise produces a
     * bridge that silently never works — and by the startup check.
     *
     * A lookup that *failed* is not proof a room is gone: reject, or resolve to
     * a {@see Room}, rather than resolving to `null` on a network error.
     *
     * @return PromiseInterface<?Room>
     */
    public function resolve(string $target): PromiseInterface;

    /**
     * Turns however somebody referred to a room into the form this connector
     * addresses it by, or `null` when it is not a room reference at all.
     *
     * `twitch.tv/Foo` and `#Foo` are both `foo`; `t.me/name` is `@name`. The
     * result is what gets stored, so it must be stable — normalising one way on
     * Monday and another on Tuesday orphans every bridge made before the change.
     */
    public function normalise(string $input): ?string;

    /**
     * Every room the connector is currently in.
     *
     * The startup check compares this against what was restored from disk: a
     * join that silently failed leaves a bridge that works in one direction
     * only, which is invisible from the configuration alone.
     *
     * @return list<string>
     */
    public function joined(): array;

    /** How many outbound messages are waiting on this connector's own pacing. */
    public function queued(): int;
}

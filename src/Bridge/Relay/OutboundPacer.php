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

namespace Bridge\Relay;

use Bridge\Support\RateLimiter;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Paces what the bot says *into* Discord, per channel, across every connector.
 *
 * `Discord\Http` already queues per rate-limit bucket and caps concurrency, and
 * everything here goes through it — nothing in this project may hold a second
 * HTTP client. What that machinery cannot do is decline to make a request in
 * the first place, and the arithmetic changed when the platforms were merged:
 *
 * - Two separate bots were two applications with two tokens, and therefore two
 *   50-requests-per-second budgets. One bot has one.
 * - One message on a busy network fans out to *every* Discord channel following
 *   that room, across unrelated servers.
 * - Two connectors relaying into the same channel each stay under the limit on
 *   their own and breach it together, which is exactly the failure a per-sender
 *   limit cannot see.
 *
 * So relayed traffic is throttled at the source, by destination channel,
 * leaving the budget for the things a person is waiting on. Interaction
 * responses deliberately do *not* come through here: Discord documents them as
 * exempt from the global limit, so a command keeps answering while the relay is
 * saturated.
 *
 * Bursts are not the enemy — a token bucket lets ordinary chat through
 * immediately and only slows a genuine flood. A channel out of tokens is
 * skipped over rather than stalling the whole queue behind it.
 *
 * @link https://docs.discord.com/developers/topics/rate-limits
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class OutboundPacer
{
    /**
     * Discord's per-channel message sublimit: five in five seconds.
     *
     * Not published as a header — it is a documented property of the message
     * routes — so it is encoded here rather than discovered from a 429.
     */
    public const CAPACITY = 5;

    public const PER = 5.0;

    /** @var list<array{channel: string, send: \Closure, deferred: Deferred}> */
    private array $queue = [];

    /** @var array<string, RateLimiter> */
    private array $limiters = [];

    private bool $draining = false;

    /**
     * @param (callable(): float)|null $clock Defaults to `microtime(true)`; injected for tests.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly int $capacity = self::CAPACITY,
        private readonly float $per = self::PER,
        private $clock = null,
    ) {
    }

    /**
     * Runs `$send` as soon as the channel's budget allows, and resolves with
     * whatever it returns.
     *
     * The caller gets a promise rather than a callback so a failure — a webhook
     * that has been deleted, a channel the bot was removed from — still
     * surfaces at the point that asked for the send.
     *
     * @param (callable(): PromiseInterface) $send
     */
    public function enqueue(string $channelId, callable $send): PromiseInterface
    {
        $deferred = new Deferred();

        $this->queue[] = [
            'channel' => $channelId,
            'send' => \Closure::fromCallable($send),
            'deferred' => $deferred,
        ];

        $this->drain();

        return $deferred->promise();
    }

    /** How many sends are waiting, for logging and health checks. */
    public function queued(): int
    {
        return count($this->queue);
    }

    /** How many are waiting on one channel. */
    public function queuedFor(string $channelId): int
    {
        return count(array_filter($this->queue, static fn (array $i): bool => $i['channel'] === $channelId));
    }

    /**
     * Sends whatever the budget allows, then re-arms a timer for the rest.
     *
     * Head-of-line blocking is avoided by skipping over a channel that is out
     * of tokens instead of stalling the whole queue behind it: one busy channel
     * should not delay a quiet one in another server.
     */
    private function drain(): void
    {
        $deferredItems = [];
        $soonest = null;

        while ($this->queue !== []) {
            $item = array_shift($this->queue);
            $limiter = $this->limiters[$item['channel']] ??= new RateLimiter($this->capacity, $this->per, $this->clock);

            if ($limiter->tryConsume()) {
                // A send that throws rather than rejecting must not escape the
                // loop: everything already set aside below would go with it,
                // and those sends would simply never happen.
                try {
                    $item['deferred']->resolve(($item['send'])());
                } catch (\Throwable $e) {
                    $item['deferred']->reject($e);
                }

                continue;
            }

            $deferredItems[] = $item;
            $wait = $limiter->retryAfter();
            $soonest = $soonest === null ? $wait : min($soonest, $wait);
        }

        $this->queue = $deferredItems;

        if ($this->queue === [] || $this->draining) {
            return;
        }

        $this->draining = true;
        $this->loop->addTimer(max(0.1, $soonest ?? 0.1), function (): void {
            $this->draining = false;
            $this->drain();
        });
    }
}

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

namespace Bridge\Support;

/**
 * A token bucket: how the bot stays under somebody else's send limit.
 *
 * Every network the bot speaks on publishes one, and the penalty for breaching
 * it is rarely just a dropped message — Twitch mutes the *account* for thirty
 * minutes, Discord counts rejections toward a ban on the whole host. A busy
 * Discord channel will happily exceed any of them, so each sender paces itself
 * rather than finding out.
 *
 * A bucket rather than a fixed delay: ordinary chat goes out immediately and
 * only a genuine burst is slowed. The clock is injected so the behaviour can be
 * tested without sleeping.
 *
 * The defaults are a conservative chat-sized bucket and not a claim about any
 * particular network. A caller that knows its own limit should say so —
 * {@see \Bridge\Relay\OutboundPacer} passes Discord's, and a connector passes
 * whatever its own network publishes, with the margin it wants.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class RateLimiter
{
    private float $tokens;

    private float $updatedAt;

    /** @var (callable(): float) */
    private $clock;

    /**
     * @param int                   $capacity How many messages may burst.
     * @param float                 $per      Over how many seconds the bucket refills.
     * @param (callable(): float)|null $clock  Defaults to `microtime(true)`.
     */
    public function __construct(
        private readonly int $capacity = 18,
        private readonly float $per = 30.0,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->tokens = (float) $capacity;
        $this->updatedAt = ($this->clock)();
    }

    /**
     * Takes one token if any is available.
     *
     * A caller is expected to size the bucket *under* the published limit: the
     * bot is rarely the only thing speaking as its account, and being silenced
     * for half an hour is a far worse failure than a message arriving a second
     * late.
     */
    public function tryConsume(): bool
    {
        $this->refill();

        if ($this->tokens < 1.0) {
            return false;
        }

        $this->tokens -= 1.0;

        return true;
    }

    /** Seconds until the next token is available; `0.0` when one is ready now. */
    public function retryAfter(): float
    {
        $this->refill();

        if ($this->tokens >= 1.0) {
            return 0.0;
        }

        return (1.0 - $this->tokens) * ($this->per / $this->capacity);
    }

    /** Tokens currently available, for logging and tests. */
    public function available(): float
    {
        $this->refill();

        return $this->tokens;
    }

    private function refill(): void
    {
        $now = ($this->clock)();
        $elapsed = $now - $this->updatedAt;

        if ($elapsed <= 0) {
            return;
        }

        $this->tokens = min((float) $this->capacity, $this->tokens + $elapsed * ($this->capacity / $this->per));
        $this->updatedAt = $now;
    }
}

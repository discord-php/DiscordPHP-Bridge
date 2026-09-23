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

/**
 * How long somebody must wait before running the same command again.
 *
 * Each {@see Action} declares its own `cooldown`, and it used to be enforced by
 * whichever command client registered it — DiscordPHP's for prefix commands,
 * TwitchPHP's for chat. Once every surface routes through one qualifier per
 * connector, neither client sees the individual commands any more, so the
 * cooldown has to live somewhere every adapter can reach. This is that place,
 * and it is the same on every surface: `!twitch clip` on Twitch and
 * `/twitch stream clip` on Discord share one clock.
 *
 * Per person, per command. A per-command cooldown shared by everyone would let
 * one person lock a command for a whole chat.
 *
 * The clock is injected, so none of this sleeps in a test.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Cooldowns
{
    /** Past this many entries, expired ones are swept on the next claim. */
    private const SWEEP_AT = 1000;

    /** @var array<string, float> key => when the cooldown ends */
    private array $until = [];

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @param (callable(): float)|null $clock Defaults to `microtime(true)`. */
    public function __construct(?callable $clock = null)
    {
        $this->clock = \Closure::fromCallable($clock ?? static fn (): float => microtime(true));
    }

    /**
     * Claims a run of `$action` for `$invoker`, or says how long is left.
     *
     * Returns `0` and starts the cooldown when the command may run now; returns
     * the whole seconds remaining, rounded up, when it may not — and does *not*
     * restart the clock, so somebody hammering a command is not punished with
     * an ever-longer wait.
     */
    public function claim(Action $action, string $invoker): int
    {
        if ($action->cooldown <= 0 || $invoker === '') {
            return 0;
        }

        $now = ($this->clock)();
        $key = $action->key() . "\0" . $invoker;
        $until = $this->until[$key] ?? 0.0;

        if ($until > $now) {
            return (int) ceil($until - $now);
        }

        if (count($this->until) >= self::SWEEP_AT) {
            $this->until = array_filter($this->until, static fn (float $end): bool => $end > $now);
        }

        $this->until[$key] = $now + $action->cooldown;

        return 0;
    }

    /** How many cooldowns are being tracked, for tests. */
    public function count(): int
    {
        return count($this->until);
    }
}

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

namespace Bridge\Tests;

use Bridge\Command\Action;
use Bridge\Command\Cooldowns;
use PHPUnit\Framework\TestCase;

/**
 * One clock per person per command, shared by every surface the command can
 * be typed on.
 */
final class CooldownsTest extends TestCase
{
    private float $now = 1000.0;

    public function testTheFirstRunIsFree(): void
    {
        $this->assertSame(0, $this->cooldowns()->claim($this->action(30), 'twitch:1'));
    }

    public function testASecondRunWaitsOutTheRestRoundedUp(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->claim($this->action(30), 'twitch:1');

        $this->now += 10.5;

        $this->assertSame(20, $cooldowns->claim($this->action(30), 'twitch:1'));
    }

    public function testHammeringDoesNotRestartTheClock(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->claim($this->action(30), 'twitch:1');

        for ($i = 0; $i < 5; ++$i) {
            $this->now += 5.0;
            $cooldowns->claim($this->action(30), 'twitch:1');
        }

        $this->now += 5.0;

        // Thirty seconds after the first claim, not after the last.
        $this->assertSame(0, $cooldowns->claim($this->action(30), 'twitch:1'));
    }

    public function testOnePersonCannotLockACommandForEveryoneElse(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->claim($this->action(30), 'twitch:1');

        $this->assertSame(0, $cooldowns->claim($this->action(30), 'twitch:2'));
    }

    public function testDifferentCommandsKeepDifferentClocks(): void
    {
        $cooldowns = $this->cooldowns();
        $cooldowns->claim($this->action(30, 'clip'), 'twitch:1');

        $this->assertSame(0, $cooldowns->claim($this->action(30, 'uptime'), 'twitch:1'));
    }

    public function testACommandWithoutACooldownIsNeverTracked(): void
    {
        $cooldowns = $this->cooldowns();

        $this->assertSame(0, $cooldowns->claim($this->action(0), 'twitch:1'));
        $this->assertSame(0, $cooldowns->claim($this->action(0), 'twitch:1'));
        $this->assertSame(0, $cooldowns->count());
    }

    public function testNobodyInParticularIsNotRateLimited(): void
    {
        // An invoker the surface could not identify must not share one clock
        // with every other unidentified person.
        $this->assertSame(0, $this->cooldowns()->claim($this->action(30), ''));
    }

    public function testExpiredEntriesAreSweptRatherThanKeptForever(): void
    {
        $cooldowns = $this->cooldowns();

        for ($i = 0; $i < 1000; ++$i) {
            $cooldowns->claim($this->action(5), 'twitch:' . $i);
        }

        $this->now += 60.0;
        $cooldowns->claim($this->action(5), 'twitch:late');

        $this->assertSame(1, $cooldowns->count());
    }

    private function cooldowns(): Cooldowns
    {
        return new Cooldowns(fn (): float => $this->now);
    }

    private function action(int $cooldown, string $name = 'clip'): Action
    {
        return new Action('twitch', $name, static fn () => null, cooldown: $cooldown);
    }
}

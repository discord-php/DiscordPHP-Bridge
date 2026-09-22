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

namespace Bridge\Tests;

use Bridge\Links;
use PHPUnit\Framework\TestCase;

/**
 * Routing, with no gateway and no socket in sight.
 *
 * The asymmetry is the thing worth pinning down: one Discord channel points at
 * one target, but one target fans out to every Discord channel following it,
 * across unrelated servers.
 */
final class LinksTest extends TestCase
{
    public function testADiscordChannelPointsAtOneTarget(): void
    {
        $links = new Links(['g1' => ['c1' => 'coffeescrafts']]);

        $this->assertSame('coffeescrafts', $links->targetFor('c1'));
        $this->assertNull($links->targetFor('c2'));
    }

    public function testOneTargetFansOutToEveryChannelFollowingIt(): void
    {
        $links = new Links([
            'g1' => ['c1' => 'coffeescrafts'],
            'g2' => ['c2' => 'coffeescrafts', 'c3' => 'valgorithms'],
        ]);

        $this->assertSame(['c1', 'c2'], $links->discordFor('coffeescrafts'));
        $this->assertSame(['c3'], $links->discordFor('valgorithms'));
        $this->assertSame([], $links->discordFor('nobody'));
    }

    public function testTwoServersFollowingTheSameTargetAreOneMembership(): void
    {
        $links = new Links([
            'g1' => ['c1' => 'coffeescrafts'],
            'g2' => ['c2' => 'coffeescrafts'],
        ]);

        $this->assertSame(['coffeescrafts'], $links->targets());
        $this->assertSame(2, $links->count());
    }

    public function testATargetEchoedBackInADifferentCaseStillRoutes(): void
    {
        // IRC does exactly this. Matching only exactly would deliver nothing,
        // which reads like a bridge that was never set up.
        $links = new Links(['g1' => ['c1' => 'coffeescrafts']]);

        $this->assertSame(['c1'], $links->discordFor('CoffeesCrafts'));
        $this->assertTrue($links->isBridged('COFFEESCRAFTS'));
    }

    public function testNumericTargetsComeBackAsStrings(): void
    {
        // PHP turns "-1001234567890" into an int when it is used as a key, and
        // a caller comparing strictly against the strings used everywhere else
        // would then match nothing.
        $links = new Links(['g1' => ['c1' => '-1001234567890']]);

        $this->assertSame(['-1001234567890'], $links->targets());
        $this->assertSame('-1001234567890', $links->targetFor('c1'));
        $this->assertSame(['c1'], $links->discordFor('-1001234567890'));
    }

    public function testGuildIdsComeBackAsStringsToo(): void
    {
        $links = new Links(['115233111977099271' => ['c1' => 'x']]);

        $this->assertSame(['115233111977099271'], $links->guilds());
        $this->assertSame(['c1' => 'x'], $links->forGuild('115233111977099271'));
    }

    public function testAnUnconfiguredServerHasNoLinks(): void
    {
        $this->assertSame([], (new Links())->forGuild('g1'));
        $this->assertTrue((new Links())->isEmpty());
        $this->assertSame(0, (new Links())->count());
    }

    public function testTheDiffSaysWhatToJoinAndWhatToLeave(): void
    {
        // A diff rather than "rejoin everything", so one server's change does
        // not blink every other server's bridge offline.
        $before = new Links(['g1' => ['c1' => 'a', 'c2' => 'b']]);
        $after = new Links(['g1' => ['c1' => 'a'], 'g2' => ['c3' => 'c']]);

        $this->assertSame(['join' => ['c'], 'part' => ['b']], $before->diff($after));
    }

    public function testADiffWithNothingToDoIsEmpty(): void
    {
        $links = new Links(['g1' => ['c1' => 'a']]);

        $this->assertSame(['join' => [], 'part' => []], $links->diff(new Links(['g2' => ['c9' => 'a']])));
    }
}

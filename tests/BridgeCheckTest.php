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

use Bridge\Support\BridgeCheck;
use PHPUnit\Framework\TestCase;

/**
 * A bridge that stopped working while the bot was down looks exactly like one
 * nobody has spoken in. These are the words that tell the two apart.
 */
final class BridgeCheckTest extends TestCase
{
    public function testAWorkingBridgeSaysNothing(): void
    {
        $this->assertNull(BridgeCheck::describe($this->row()));
    }

    public function testAMissingDiscordChannelIsNamed(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(channel_ok: false));

        $this->assertStringContainsString('can\'t see that Discord channel', $problem);
        $this->assertStringContainsString('twitch coffeescrafts', $problem);
    }

    public function testAMissingRoomIsNamed(): void
    {
        $this->assertStringContainsString(
            'no such room any more',
            (string) BridgeCheck::describe($this->row(target_ok: false)),
        );
    }

    public function testBothEndsGoneIsReportedOnceRatherThanTwice(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(channel_ok: false, target_ok: false));

        $this->assertStringContainsString('neither end is reachable', $problem);
    }

    public function testARoomThatExistsButWasNotJoinedIsTheInterestingCase(): void
    {
        // The one thing a restart is supposed to re-establish by itself. A join
        // that silently failed leaves a bridge working in one direction only.
        $this->assertStringContainsString(
            'not in it',
            (string) BridgeCheck::describe($this->row(joined: false)),
        );
    }

    public function testTheSummaryCountsTheHealthyAndNamesTheRest(): void
    {
        $summary = BridgeCheck::summarise([
            $this->row(),
            $this->row(channel_id: '2', channel_ok: false),
            $this->row(channel_id: '3', connector: 'telegram', target: '-100', joined: false),
        ]);

        $this->assertSame(1, $summary['healthy']);
        $this->assertCount(2, $summary['problems']);
        $this->assertStringContainsString('telegram -100', $summary['problems'][1]);
    }

    public function testNothingConfiguredIsNotAProblem(): void
    {
        $this->assertSame(['healthy' => 0, 'problems' => []], BridgeCheck::summarise([]));
    }

    public function testTheRestoredLineNamesWhereItCameFrom(): void
    {
        $this->assertSame(
            'restored 3 bridges across 2 servers (telegram, twitch) from storage/bridges.json',
            BridgeCheck::restored(3, 2, 'storage/bridges.json', ['telegram', 'twitch']),
        );
    }

    public function testTheRestoredLineIsSingularWhenItShouldBe(): void
    {
        $this->assertSame(
            'restored 1 bridge across 1 server (twitch) from x.json',
            BridgeCheck::restored(1, 1, 'x.json', ['twitch']),
        );
    }

    public function testAnEmptyStoreSaysSoRatherThanClaimingToHaveRestoredNothing(): void
    {
        $this->assertSame('no bridges configured yet (x.json)', BridgeCheck::restored(0, 0, 'x.json'));
    }

    /**
     * @return array{connector: string, channel_id: string, target: string, channel_ok: bool, target_ok: bool, joined: bool}
     */
    private function row(
        string $connector = 'twitch',
        string $channel_id = '1',
        string $target = 'coffeescrafts',
        bool $channel_ok = true,
        bool $target_ok = true,
        bool $joined = true,
    ): array {
        return compact('connector', 'channel_id', 'target', 'channel_ok', 'target_ok', 'joined');
    }
}

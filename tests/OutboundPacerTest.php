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

use Bridge\Relay\OutboundPacer;
use Bridge\Tests\Doubles\ManualLoop;
use PHPUnit\Framework\TestCase;

use function React\Promise\resolve;

/**
 * One bot, one token, one 50-requests-per-second budget — shared by every
 * connector relaying into Discord.
 *
 * The clock is injected, so none of this sleeps.
 */
final class OutboundPacerTest extends TestCase
{
    private ManualLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new ManualLoop(1000.0);
    }

    public function testOrdinaryChatIsNotDelayedAtAll(): void
    {
        $sent = [];
        $pacer = $this->pacer();

        $pacer->enqueue('c1', $this->recorder($sent, 'a'));
        $pacer->enqueue('c1', $this->recorder($sent, 'b'));

        $this->assertSame(['a', 'b'], $sent);
        $this->assertSame(0, $pacer->queued());
    }

    public function testABurstBeyondTheChannelSublimitIsHeldBack(): void
    {
        // Discord allows five messages per five seconds in one channel.
        $sent = [];
        $pacer = $this->pacer();

        for ($i = 0; $i < 8; ++$i) {
            $pacer->enqueue('c1', $this->recorder($sent, (string) $i));
        }

        $this->assertCount(OutboundPacer::CAPACITY, $sent);
        $this->assertSame(3, $pacer->queued());
    }

    public function testHeldBackSendsGoOutOnceTheBucketRefills(): void
    {
        $sent = [];
        $pacer = $this->pacer();

        for ($i = 0; $i < 7; ++$i) {
            $pacer->enqueue('c1', $this->recorder($sent, (string) $i));
        }

        $this->assertCount(5, $sent);

        // Far enough ahead for the bucket to be full again. The timer the
        // pacer armed is what actually releases them.
        $this->loop->advance(10.0);

        $this->assertCount(7, $sent);
        $this->assertSame(0, $pacer->queued());
    }

    public function testABusyChannelDoesNotStallAQuietOne(): void
    {
        // Head-of-line blocking would mean one popular stream delaying every
        // other server's bridge.
        $sent = [];
        $pacer = $this->pacer();

        for ($i = 0; $i < 6; ++$i) {
            $pacer->enqueue('busy', $this->recorder($sent, 'busy'));
        }

        $pacer->enqueue('quiet', $this->recorder($sent, 'quiet'));

        $this->assertContains('quiet', $sent);
        $this->assertSame(1, $pacer->queuedFor('busy'));
        $this->assertSame(0, $pacer->queuedFor('quiet'));
    }

    public function testTwoConnectorsIntoOneChannelShareTheBudget(): void
    {
        // The failure a per-sender limit cannot see: each stays under it alone
        // and they breach it together.
        $sent = [];
        $pacer = $this->pacer();

        for ($i = 0; $i < 3; ++$i) {
            $pacer->enqueue('c1', $this->recorder($sent, 'twitch'));
            $pacer->enqueue('c1', $this->recorder($sent, 'telegram'));
        }

        $this->assertCount(OutboundPacer::CAPACITY, $sent);
        $this->assertSame(1, $pacer->queued());
    }

    public function testTheCallerStillSeesWhatTheSendResolvedTo(): void
    {
        $answer = null;
        $this->pacer()
            ->enqueue('c1', static fn () => resolve('sent'))
            ->then(function (mixed $value) use (&$answer): void {
                $answer = $value;
            });

        $this->assertSame('sent', $answer);
    }

    public function testASendThatThrowsRejectsInsteadOfStallingTheQueue(): void
    {
        // A send that blows up before it has a promise to reject — a bad
        // argument, a part missing a property — must still settle, or the
        // caller waits forever and the channel's queue never drains.
        $sent = [];
        $error = null;
        $pacer = $this->pacer();

        $pacer->enqueue('c1', static function (): never {
            throw new \RuntimeException('boom');
        })->then(null, function (\Throwable $e) use (&$error): void {
            $error = $e->getMessage();
        });

        $pacer->enqueue('c1', $this->recorder($sent, 'after'));

        $this->assertSame('boom', $error);
        $this->assertSame(['after'], $sent);
    }

    private function pacer(): OutboundPacer
    {
        return new OutboundPacer(
            $this->loop,
            OutboundPacer::CAPACITY,
            OutboundPacer::PER,
            $this->loop->clock(),
        );
    }

    /**
     * @param list<string> $sent
     */
    private function recorder(array &$sent, string $label): callable
    {
        return static function () use (&$sent, $label) {
            $sent[] = $label;

            return resolve(true);
        };
    }
}

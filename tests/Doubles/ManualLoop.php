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

namespace Bridge\Tests\Doubles;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

/**
 * An event loop that only moves when a test says so.
 *
 * Anything paced by a timer — {@see \Bridge\Relay\OutboundPacer}, a connector's
 * own send queue — otherwise has to be tested either by sleeping, which makes
 * the suite slow and flaky, or by running a real loop, which never stops.
 * Here time is a number the test advances, and {@see tick()} runs whatever has
 * come due.
 */
final class ManualLoop implements LoopInterface
{
    /** @var list<array{at: float, interval: ?float, timer: TimerInterface, callback: \Closure}> */
    private array $timers = [];

    /** @var list<\Closure> */
    private array $future = [];

    public function __construct(private float $now = 0.0)
    {
    }

    /** The clock the code under test should read, so both agree on "now". */
    public function clock(): callable
    {
        return fn (): float => $this->now;
    }

    /** Moves time forward and runs everything that comes due on the way. */
    public function advance(float $seconds): void
    {
        $this->now += $seconds;
        $this->tick();
    }

    /** Runs due timers and queued ticks, repeatedly, until nothing is left. */
    public function tick(): void
    {
        // A timer may queue another; a bounded number of passes keeps a
        // runaway from hanging the suite the way a real loop would.
        for ($pass = 0; $pass < 100; ++$pass) {
            $ran = false;

            foreach ($this->future as $callback) {
                $this->future = [];
                $callback();
                $ran = true;
            }

            foreach ($this->timers as $index => $entry) {
                if ($entry['at'] > $this->now) {
                    continue;
                }

                if ($entry['interval'] === null) {
                    unset($this->timers[$index]);
                } else {
                    $this->timers[$index]['at'] = $this->now + $entry['interval'];
                }

                ($entry['callback'])($entry['timer']);
                $ran = true;
            }

            $this->timers = array_values($this->timers);

            if (! $ran) {
                return;
            }
        }
    }

    /** How many timers are armed, for asserting that something was scheduled. */
    public function armed(): int
    {
        return count($this->timers);
    }

    public function addTimer($interval, $callback): TimerInterface
    {
        $timer = new class () implements TimerInterface {
            public function getInterval(): float
            {
                return 0.0;
            }

            public function getCallback(): callable
            {
                return static fn () => null;
            }

            public function isPeriodic(): bool
            {
                return false;
            }
        };

        $this->timers[] = [
            'at' => $this->now + (float) $interval,
            'interval' => null,
            'timer' => $timer,
            'callback' => \Closure::fromCallable($callback),
        ];

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback): TimerInterface
    {
        $timer = $this->addTimer($interval, $callback);
        $this->timers[count($this->timers) - 1]['interval'] = (float) $interval;

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer): void
    {
        $this->timers = array_values(array_filter(
            $this->timers,
            static fn (array $entry): bool => $entry['timer'] !== $timer,
        ));
    }

    public function futureTick($listener): void
    {
        $this->future[] = \Closure::fromCallable($listener);
    }

    // Nothing under test touches streams or signals.

    public function addReadStream($stream, $listener): void
    {
    }

    public function addWriteStream($stream, $listener): void
    {
    }

    public function removeReadStream($stream): void
    {
    }

    public function removeWriteStream($stream): void
    {
    }

    public function addSignal($signal, $listener): void
    {
    }

    public function removeSignal($signal, $listener): void
    {
    }

    public function run(): void
    {
        $this->tick();
    }

    public function stop(): void
    {
    }
}

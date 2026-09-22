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

use PHPUnit\Framework\TestCase;

/**
 * There is exactly one `Discord\Http` in the process, and everything that talks
 * to Discord goes through it.
 *
 * That object holds the per-route rate-limit buckets and the concurrency cap.
 * A second client keeps its own empty bucket table and races the first into
 * 429s against the same token — and Discord counts 10,000 rejected requests in
 * ten minutes as grounds for a Cloudflare ban on the whole host, which is not
 * a throttle anybody recovers from by waiting.
 *
 * Nothing in the code enforces this; a connector is one `new Browser` away from
 * breaking it, in a package the core never sees. So it is asserted here, over
 * the core and over every connector installed alongside it.
 *
 * @link https://docs.discord.com/developers/topics/rate-limits
 */
final class SingleHttpClientTest extends TestCase
{
    /**
     * Ways of reaching Discord that would bypass the shared client.
     *
     * `discord.com` is the giveaway: DiscordPHP's parts and repositories never
     * name it, because the base URL lives in `Discord\Http`. Anything that
     * spells it out is building a request by hand.
     */
    private const FORBIDDEN = [
        'new Browser(' => 'a ReactPHP Browser of its own',
        'new Http(' => 'a second Discord HTTP client',
        'discord.com/api' => 'a hand-built request to the Discord API',
        'curl_init(' => 'a cURL handle',
    ];

    public function testNothingBuildsItsOwnRouteToDiscord(): void
    {
        $offences = [];

        foreach ($this->sources() as $path) {
            foreach (self::scan((string) file_get_contents($path)) as $what) {
                $offences[] = sprintf('%s holds %s', $this->relative($path), $what);
            }
        }

        $this->assertSame([], $offences, implode("\n", [
            'Everything that talks to Discord must go through the one Http the bot owns.',
            ...$offences,
        ]));
    }

    public function testTheScanActuallyLooksAtSomething(): void
    {
        // A scan that silently matched nothing would pass forever.
        $this->assertGreaterThan(20, iterator_count($this->sources()));
    }

    public function testTheScanWouldCatchOneIfThereWereOne(): void
    {
        // And so would a scan that matched nothing, so it is given something.
        $this->assertNotSame([], self::scan('$browser = new Browser($loop);'));
        $this->assertNotSame([], self::scan('$this->get("https://discord.com/api/v10/users/@me");'));
        $this->assertSame([], self::scan('$channel->sendMessage($builder);'));
    }

    /**
     * What is wrong with one file's source, if anything.
     *
     * @return list<string>
     */
    private static function scan(string $code): array
    {
        $found = [];

        foreach (self::FORBIDDEN as $needle => $what) {
            if (str_contains($code, $needle)) {
                $found[] = sprintf('%s (%s)', $what, $needle);
            }
        }

        return $found;
    }

    /**
     * The core's own source, plus any connector package installed beside it.
     *
     * @return \Generator<string>
     */
    private function sources(): \Generator
    {
        $roots = [\dirname(__DIR__) . '/src'];

        foreach (glob(\dirname(__DIR__) . '/vendor/vzgcoders/discordphp-bridge-*/src') ?: [] as $connector) {
            $roots[] = $connector;
        }

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    yield $file->getPathname();
                }
            }
        }
    }

    private function relative(string $path): string
    {
        return str_replace([\dirname(__DIR__) . \DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
    }
}

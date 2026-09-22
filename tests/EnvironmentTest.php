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

use Bridge\Environment;
use PHPUnit\Framework\TestCase;

/**
 * Every connector reads its settings from here, so the parsing is worth
 * pinning down once rather than being rediscovered per package.
 */
final class EnvironmentTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/bridge-env-' . bin2hex(random_bytes(6)) . '.env';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        foreach (['BRIDGE_TEST_A', 'BRIDGE_TEST_B'] as $key) {
            putenv($key);
        }
    }

    public function testReadsSettingsFromTheFile(): void
    {
        $env = $this->write("DISCORD_TOKEN=abc\nLOG_LEVEL=debug\n");

        $this->assertSame('abc', $env->get('DISCORD_TOKEN'));
        $this->assertSame('debug', $env->get('LOG_LEVEL'));
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        $env = $this->write("# a comment\n\nDISCORD_TOKEN=abc\n   # indented\nnot-a-pair\n");

        $this->assertSame('abc', $env->get('DISCORD_TOKEN'));
    }

    public function testQuotedValuesAreUnwrapped(): void
    {
        $env = $this->write("A=\"quoted\"\nB='single'\nC=  spaced  \n");

        $this->assertSame('quoted', $env->get('A'));
        $this->assertSame('single', $env->get('B'));
        $this->assertSame('spaced', $env->get('C'));
    }

    public function testAValueMayContainAnEqualsSign(): void
    {
        // Tokens routinely do.
        $env = $this->write("TOKEN=a=b=c\n");

        $this->assertSame('a=b=c', $env->get('TOKEN'));
    }

    public function testTheProcessEnvironmentWins(): void
    {
        // So a container can override a setting without the file being edited.
        putenv('BRIDGE_TEST_A=from-process');
        $env = $this->write("BRIDGE_TEST_A=from-file\n");

        $this->assertSame('from-process', $env->get('BRIDGE_TEST_A'));
    }

    public function testAMissingFileStillReadsTheProcessEnvironment(): void
    {
        putenv('BRIDGE_TEST_B=only-process');

        $this->assertSame('only-process', Environment::load($this->path)->get('BRIDGE_TEST_B'));
    }

    public function testAnAbsentSettingIsNullRatherThanEmpty(): void
    {
        $this->assertNull($this->write("A=1\n")->get('NOPE'));
    }

    public function testAnEmptyValueCountsAsAbsent(): void
    {
        // `TWITCH_CLIENT_SECRET=` in a file means "not set", not "set to ''".
        $this->assertNull($this->write("A=\n")->get('A'));
        $this->assertSame('fallback', $this->write("A=\n")->or('A', 'fallback'));
    }

    public function testRequireNamesWhatIsMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DISCORD_TOKEN/');

        $this->write("A=1\n")->require('DISCORD_TOKEN');
    }

    public function testHasAnswersWhetherAConnectorCanBeInstalledAtAll(): void
    {
        $env = $this->write("TWITCH_CLIENT_ID=x\nTWITCH_NICK=y\n");

        $this->assertTrue($env->has('TWITCH_CLIENT_ID', 'TWITCH_NICK'));
        $this->assertFalse($env->has('TWITCH_CLIENT_ID', 'TELEGRAM_TOKEN'));
    }

    public function testSettingsCanBeSuppliedDirectly(): void
    {
        $this->assertSame('x', Environment::fromArray(['A' => 'x'])->get('A'));
    }

    private function write(string $contents): Environment
    {
        file_put_contents($this->path, $contents);

        return Environment::load($this->path);
    }
}

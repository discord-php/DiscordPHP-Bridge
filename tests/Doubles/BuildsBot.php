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

use Bridge\Bot;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Store;
use Bridge\Support\Filesystem;
use Psr\Log\NullLogger;

/**
 * A real {@see Bot}, never connected.
 *
 * On a {@see ManualLoop} nothing runs unless a test advances it, so the bot
 * can be constructed, given connectors and asked questions without a gateway
 * — which is what lets the dispatcher and the relay be tested against the
 * real thing rather than against a mock of it.
 */
trait BuildsBot
{
    private ?string $botDir = null;

    /** @param array<string, string> $settings */
    private function bot(array $settings = []): Bot
    {
        $this->botDir ??= sys_get_temp_dir() . '/bridge-bot-' . bin2hex(random_bytes(6));
        @mkdir($this->botDir, 0o777, true);

        $path = $this->botDir . '/bridges.json';

        return new Bot(
            Config::fromEnvironment(Environment::fromArray($settings + ['DISCORD_TOKEN' => 'test.token.here']), $path),
            new Store($path, Filesystem::blocking()),
            ['logger' => new NullLogger(), 'loop' => new ManualLoop()],
        );
    }

    private function removeBotDir(): void
    {
        if ($this->botDir === null) {
            return;
        }

        foreach (glob($this->botDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->botDir);
        $this->botDir = null;
    }
}

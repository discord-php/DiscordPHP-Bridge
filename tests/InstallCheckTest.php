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

use Bridge\Support\InstallCheck;
use Bridge\Tests\Doubles\BuildsBot;
use Discord\Parts\OAuth\Application;
use PHPUnit\Framework\TestCase;

/**
 * The Developer Portal settings that decide who can add the bot, read back at
 * startup — against DiscordPHP's own `Application` part, since that is what
 * the bot hands it.
 */
final class InstallCheckTest extends TestCase
{
    use BuildsBot;

    private const PAGE = 'https://www.valgorithms.com/discord.html';

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    public function testAPrivateBotWithItsInstallPageRegisteredIsQuiet(): void
    {
        $findings = InstallCheck::review($this->application([
            'custom_install_url' => self::PAGE . '?app=bridge',
            'redirect_uris' => [self::PAGE],
        ]));

        $this->assertSame([], $this->warnings($findings));
        $this->assertSame(['install link: ' . self::PAGE . '?app=bridge'], $this->messages($findings, InstallCheck::INFO));
    }

    public function testAPublicBotIsCalledOut(): void
    {
        $warnings = $this->warnings(InstallCheck::review($this->application(['bot_public' => true])));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Public Bot is on', $warnings[0]);
    }

    public function testRequiringTheCodeGrantIsCalledOut(): void
    {
        // Nothing here exchanges the code, so the bot would never join.
        $warnings = $this->warnings(InstallCheck::review($this->application([
            'bot_require_code_grant' => true,
            'custom_install_url' => self::PAGE,
            'redirect_uris' => [self::PAGE],
        ])));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Code Grant', $warnings[0]);
    }

    public function testAnInstallPageThatIsNotARegisteredRedirectIsCalledOut(): void
    {
        $warnings = $this->warnings(InstallCheck::review($this->application([
            'custom_install_url' => self::PAGE . '?app=bridge',
            // The apex, not www: a different redirect as far as Discord cares.
            'redirect_uris' => ['https://valgorithms.com/discord.html'],
        ])));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString(self::PAGE, $warnings[0]);
    }

    public function testUserInstallIsMentioned(): void
    {
        $findings = InstallCheck::review($this->application([
            'custom_install_url' => self::PAGE,
            'redirect_uris' => [self::PAGE],
            'integration_types_config' => (object) ['0' => (object) [], '1' => (object) []],
        ]));

        $this->assertStringContainsString('User Install', implode(' ', $this->messages($findings, InstallCheck::INFO)));
    }

    public function testAPrivateBotWithNoInstallLinkIsNotAnError(): void
    {
        $findings = InstallCheck::review($this->application([]));

        $this->assertSame([], $this->warnings($findings));
        $this->assertStringContainsString('no install link', implode(' ', $this->messages($findings, InstallCheck::INFO)));
    }

    /** @param array<string, mixed> $fields */
    private function application(array $fields): Application
    {
        return new Application($this->bot(), $fields + [
            'id' => '1548742142011121785',
            'bot_public' => false,
            'bot_require_code_grant' => false,
            'integration_types_config' => (object) ['0' => (object) []],
        ], true);
    }

    /**
     * @param  list<array{level: string, message: string}> $findings
     * @return list<string>
     */
    private function warnings(array $findings): array
    {
        return $this->messages($findings, InstallCheck::WARNING);
    }

    /**
     * @param  list<array{level: string, message: string}> $findings
     * @return list<string>
     */
    private function messages(array $findings, string $level): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['message'],
            array_filter($findings, static fn (array $f): bool => $f['level'] === $level),
        ));
    }
}

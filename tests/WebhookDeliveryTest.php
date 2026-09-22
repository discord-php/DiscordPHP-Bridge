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

use Bridge\Relay\WebhookDelivery;
use PHPUnit\Framework\TestCase;

/**
 * Discord has opinions about webhook usernames, and a relayed message should
 * not be dropped over any of them.
 */
final class WebhookDeliveryTest extends TestCase
{
    public function testTheNameSaysWhichNetworkItCameFrom(): void
    {
        // Otherwise a relayed line is indistinguishable from a Discord account
        // with the same display name.
        $this->assertSame('ada (twitch)', WebhookDelivery::safeUsername('ada', ' (twitch)'));
    }

    public function testTheWordDiscordIsNeutralised(): void
    {
        // Discord rejects the whole request rather than the name.
        $this->assertStringNotContainsStringIgnoringCase(
            'discord',
            WebhookDelivery::safeUsername('discordfan', ' (twitch)'),
        );
    }

    public function testLongNamesStayWithinTheLimit(): void
    {
        $name = WebhookDelivery::safeUsername(str_repeat('a', 200), ' (telegram)');

        $this->assertLessThanOrEqual(WebhookDelivery::USERNAME_LIMIT, mb_strlen($name));
    }

    public function testTruncationLeavesRoomForTheSuffix(): void
    {
        $suffix = ' (telegram)';
        $name = WebhookDelivery::safeUsername(str_repeat('b', 200), $suffix);

        $this->assertStringEndsWith($suffix, $name);
    }

    public function testAnEmptyNameFallsBackToAPlaceholder(): void
    {
        $this->assertSame('someone (twitch)', WebhookDelivery::safeUsername('   ', ' (twitch)'));
    }

    public function testNamesAreCountedInCharactersNotBytes(): void
    {
        $name = WebhookDelivery::safeUsername(str_repeat('é', 100), ' (twitch)');

        $this->assertLessThanOrEqual(WebhookDelivery::USERNAME_LIMIT, mb_strlen($name));
    }

    public function testASuffixIsOptional(): void
    {
        $this->assertSame('ada', WebhookDelivery::safeUsername('ada'));
    }
}

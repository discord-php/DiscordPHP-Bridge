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

use Bridge\Command\Surface;
use Bridge\Support\Format;
use PHPUnit\Framework\TestCase;

/**
 * One answer, two chats.
 *
 * A Discord channel takes 2000 characters and renders Markdown; a Twitch one
 * takes 500 and renders none. These helpers are what let an action produce a
 * single result without branching on the surface itself.
 */
final class FormatTest extends TestCase
{
    public function testTwitchFieldsAreASingleLine(): void
    {
        $rendered = Format::fields(self::chat(), ['viewers' => 12, 'uptime' => '3h 14m']);

        self::assertStringNotContainsString("\n", $rendered);
        self::assertStringContainsString('viewers: 12', $rendered);
        self::assertStringContainsString('uptime: 3h 14m', $rendered);
    }

    public function testDiscordFieldsAreFenced(): void
    {
        $rendered = Format::fields(Surface::discord(), ['viewers' => 12], 'Live');

        self::assertStringContainsString('```', $rendered);
        self::assertStringContainsString('**Live**', $rendered);
    }

    public function testEmptyValuesAreDropped(): void
    {
        $rendered = Format::fields(self::chat(), ['title' => 'x', 'delay' => null, 'tags' => '']);

        self::assertStringContainsString('title: x', $rendered);
        self::assertStringNotContainsString('delay', $rendered);
        self::assertStringNotContainsString('tags', $rendered);
    }

    public function testBooleansRenderAsWords(): void
    {
        self::assertStringContainsString('mature: yes', Format::fields(self::chat(), ['mature' => true]));
        self::assertStringContainsString('mature: no', Format::fields(self::chat(), ['mature' => false]));
    }

    public function testAllEmptyFieldsSaysSo(): void
    {
        self::assertStringContainsString('nothing to show', Format::fields(self::chat(), ['a' => null]));
    }

    public function testTwitchListingIsCommaJoined(): void
    {
        $rendered = Format::listing(self::chat(), ['one', 'two'], 'things');

        self::assertSame('things: one, two', $rendered);
    }

    public function testDiscordListingIsNumbered(): void
    {
        $rendered = Format::listing(Surface::discord(), ['one', 'two'], 'things');

        self::assertStringContainsString('1. one', $rendered);
        self::assertStringContainsString('2. two', $rendered);
    }

    public function testEmptyListingUsesTheGivenWording(): void
    {
        self::assertStringContainsString('none yet', Format::listing(Surface::discord(), [], 'things', 'none yet'));
    }

    /** API output must not be able to break out of the code fence. */
    public function testCodeFencesInPayloadAreNeutralised(): void
    {
        $rendered = Format::code(Surface::discord(), 'before ``` after');
        $inner = substr($rendered, 4, -4);

        self::assertStringNotContainsString('```', $inner);
    }

    public function testTwitchCodeIsPlainAndClamped(): void
    {
        $rendered = Format::code(self::chat(), str_repeat('x', 900));

        self::assertStringNotContainsString('```', $rendered);
        self::assertLessThanOrEqual(500, mb_strlen($rendered));
    }

    public function testDiscordCodeStaysWithinTheLimit(): void
    {
        self::assertLessThanOrEqual(2000, mb_strlen(Format::code(Surface::discord(), str_repeat('y', 5000))));
    }

    public function testDurations(): void
    {
        self::assertSame('just now', Format::duration(0));
        self::assertSame('just now', Format::duration(59));
        self::assertSame('1m', Format::duration(60));
        self::assertSame('3h 14m', Format::duration(3 * 3600 + 14 * 60));
        // Only the two largest units, so it stays readable.
        self::assertSame('2d 3h', Format::duration(2 * 86400 + 3 * 3600 + 30 * 60));
    }

    public function testNumbers(): void
    {
        self::assertSame('42', Format::number(42));
        self::assertSame('999', Format::number(999));
        self::assertSame('1k', Format::number(1000));
        self::assertSame('1.5k', Format::number(1500));
        self::assertSame('2.4M', Format::number(2_400_000));
    }

    public function testClampRespectsEachSurface(): void
    {
        self::assertLessThanOrEqual(500, mb_strlen(Format::clamp(str_repeat('a', 900), self::chat())));
        self::assertLessThanOrEqual(2000, mb_strlen(Format::clamp(str_repeat('a', 5000), Surface::discord())));
        self::assertSame('short', Format::clamp('short', self::chat()));
    }

    public function testASurfaceCarriesWhatItCanTakeRatherThanItsName(): void
    {
        // The core owns Discord and nothing else: a connector declares its own,
        // so a fourth network does not need a new case in an enum here.
        self::assertSame(2000, Surface::discord()->limit);
        self::assertTrue(Surface::discord()->markdown);
        self::assertTrue(Surface::discord()->isDiscord());

        self::assertSame(500, self::chat()->limit);
        self::assertFalse(self::chat()->markdown);
        self::assertFalse(self::chat()->isDiscord());
        self::assertTrue(self::chat()->is('twitch'));
    }

    /** A flat-text, single-line chat with a short limit — IRC, in other words. */
    private static function chat(): Surface
    {
        return new Surface('twitch', 'Twitch', 500, markdown: false, lines: false);
    }
}

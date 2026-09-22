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

use Bridge\Support\MessageText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The platform-neutral half of turning one network's message into another's.
 *
 * Everything here is pure, so it is all verifiable without a socket — which is
 * the point of it living in the core rather than in a connector.
 */
final class MessageTextTest extends TestCase
{
    // ── Sanitising ─────────────────────────────────────────────────────

    public function testControlCharactersAreStripped(): void
    {
        $this->assertSame('hello', MessageText::sanitize("he\x00l\x07lo"));
    }

    public function testNewlinesAndTabsSurviveBecauseTheyCarryMeaning(): void
    {
        $this->assertSame("one\ntwo\tthree", MessageText::sanitize("one\ntwo\tthree"));
    }

    public function testWindowsLineEndingsAreNormalisedRatherThanDropped(): void
    {
        // Dropped, a message pasted from Windows arrives with its lines run
        // together.
        $this->assertSame("one\ntwo", MessageText::sanitize("one\r\ntwo"));
    }

    public function testCollapsingIsForLineOrientedSourcesOnly(): void
    {
        $this->assertSame('one two three', MessageText::collapseWhitespace("one \n two\t\tthree "));
    }

    // ── Into Discord ───────────────────────────────────────────────────

    public function testForDiscordPassesContentThroughUnescaped(): void
    {
        // Escaping here would mangle what someone actually typed; the delivery
        // side sends allowed_mentions: none instead.
        $this->assertSame('**hi** @everyone', MessageText::forDiscord('**hi** @everyone'));
    }

    public function testForDiscordKeepsLineStructureByDefault(): void
    {
        $this->assertSame("one\ntwo", MessageText::forDiscord("one\ntwo"));
    }

    public function testForDiscordFlattensWhenTheSourceCannotExpressALineBreak(): void
    {
        $this->assertSame('one two', MessageText::forDiscord("one\ntwo", flatten: true));
    }

    public function testForDiscordReturnsNullForAnEmptyMessage(): void
    {
        // An embed-only or attachment-only message has nothing to say.
        $this->assertNull(MessageText::forDiscord("   \n  "));
        $this->assertNull(MessageText::forDiscord(''));
    }

    public function testForDiscordFitsDiscordsLimit(): void
    {
        $text = MessageText::forDiscord(str_repeat('a', 5000));

        $this->assertNotNull($text);
        $this->assertLessThanOrEqual(MessageText::DISCORD_LIMIT, MessageText::length($text));
    }

    // ── Mentions ───────────────────────────────────────────────────────

    public function testMentionsResolveToNames(): void
    {
        $this->assertSame(
            '@Ada said #general to @staff',
            MessageText::resolveMentions(
                '<@1> said <#2> to <@&3>',
                ['1' => 'Ada'],
                ['2' => 'general'],
                ['3' => 'staff'],
            ),
        );
    }

    public function testUnknownMentionsDoNotLeakRawIds(): void
    {
        $this->assertSame(
            '@someone @role #channel',
            MessageText::resolveMentions('<@9> <@&9> <#9>'),
        );
    }

    public function testCustomEmojiBecomesItsName(): void
    {
        $this->assertSame(':wave: :dance:', MessageText::resolveMentions('<:wave:123> <a:dance:456>'));
    }

    // ── Attachment links ───────────────────────────────────────────────

    public function testAttachmentsRenderAsWholeLinks(): void
    {
        $this->assertSame(
            ' https://cdn.example/a.png',
            MessageText::attachmentLinks(['https://cdn.example/a.png'], 200),
        );
    }

    public function testSeveralAttachmentsAreListedWhileTheyFit(): void
    {
        $this->assertSame(
            ' https://cdn.example/a.png https://cdn.example/b.png',
            MessageText::attachmentLinks(['https://cdn.example/a.png', 'https://cdn.example/b.png'], 200),
        );
    }

    public function testAttachmentsThatDoNotFitAreCountedNotTruncated(): void
    {
        // A URL with its tail cut off looks clickable and goes nowhere, which
        // is worse than being told there are two more.
        $urls = [
            'https://cdn.example/' . str_repeat('a', 40) . '.png',
            'https://cdn.example/' . str_repeat('b', 40) . '.png',
            'https://cdn.example/' . str_repeat('c', 40) . '.png',
        ];

        $rendered = MessageText::attachmentLinks($urls, 80);

        $this->assertStringContainsString('(+2 more)', $rendered);
        $this->assertLessThanOrEqual(80, MessageText::length($rendered));
        $this->assertStringNotContainsString('bbbb', $rendered);
    }

    public function testWhenNotEvenOneLinkFitsItDegradesToACount(): void
    {
        $rendered = MessageText::attachmentLinks(['https://cdn.example/' . str_repeat('a', 200)], 20);

        $this->assertSame(' [1 file]', $rendered);
    }

    public function testNoAttachmentsRenderNothing(): void
    {
        $this->assertSame('', MessageText::attachmentLinks([], 200));
    }

    public function testTheSanitiserIsTheCallersToChoose(): void
    {
        // A connector whose network cannot carry a character passes its own.
        $this->assertSame(
            '',
            MessageText::attachmentLinks(
                ['https://cdn.example/a.png'],
                200,
                static fn (string $url): string => str_replace('https', 'http', $url),
            ),
        );
    }

    #[DataProvider('urls')]
    public function testOnlyWellFormedHttpsLinksAreRelayable(string $url, bool $expected): void
    {
        $this->assertSame($expected, MessageText::isRelayableUrl($url));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://cdn.example/a.png', true];
        yield 'plain http' => ['http://cdn.example/a.png', false];
        yield 'empty' => ['', false];
        yield 'embedded newline' => ["https://cdn.example/a.png\r\nPRIVMSG #x :hi", false];
        yield 'embedded space' => ['https://cdn.example/a b.png', false];
        yield 'not a url' => ['cdn.example/a.png', false];
    }

    // ── Structured output ──────────────────────────────────────────────

    public function testEscapeMarkdownProtectsStructuredOutput(): void
    {
        // A chat named "the_best_group" should not italicise the rest of a
        // panel.
        $this->assertSame('the\\_best\\_group', MessageText::escapeMarkdown('the_best_group'));
    }

    public function testTruncateMarksThatItHappened(): void
    {
        $this->assertSame('abc…', MessageText::truncate('abcdefg', 4));
        $this->assertSame('abc', MessageText::truncate('abc', 4));
    }

    public function testTruncateCountsCharactersRatherThanBytes(): void
    {
        $this->assertSame('éé…', MessageText::truncate('ééééé', 3));
    }

    public function testFilenameFallsBackWhenTheUrlHasNone(): void
    {
        $this->assertSame('a.png', MessageText::filename('https://cdn.example/a.png?ex=1'));
        $this->assertSame('attachment', MessageText::filename('https://cdn.example/'));
    }
}

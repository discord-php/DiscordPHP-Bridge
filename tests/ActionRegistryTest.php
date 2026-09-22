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

use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionRegistry;
use Bridge\Command\Slash;
use Bridge\Command\Surface;
use PHPUnit\Framework\TestCase;

/**
 * Every command is qualified, and the catalogue is what enforces it.
 *
 * A name is only free because no connector has claimed it yet. Since every
 * connector's commands are offered on every surface, an unqualified catalogue
 * is one installed package away from two commands answering to the same word —
 * and the loser looks broken rather than shadowed.
 */
final class ActionRegistryTest extends TestCase
{
    // ── How an action names itself ─────────────────────────────────────

    public function testAnActionKeysOnItsQualifierAndName(): void
    {
        $this->assertSame('twitch title', $this->action('twitch', 'title', 'channel')->key());
    }

    public function testTheFullPathIncludesTheGroup(): void
    {
        $action = $this->action('twitch', 'title', 'channel');

        $this->assertSame(['twitch', 'channel', 'title'], $action->path());
        $this->assertSame('twitch channel title', $action->qualified());
    }

    public function testAnUngroupedActionIsTwoDeep(): void
    {
        $action = $this->action('twitch', 'link');

        $this->assertSame(['twitch', 'link'], $action->path());
        $this->assertSame('twitch link', $action->qualified());
    }

    public function testHelpShowsTheFormTheAskingSurfaceWouldAccept(): void
    {
        $action = new Action('twitch', 'title', static fn () => null, 'Set the stream title', '[text]', group: 'channel');

        // A chat drops the group; Discord keeps it. Neither drops the qualifier.
        $this->assertSame('!twitch title [text] — Set the stream title', $action->help('!'));
        $this->assertSame('/twitch channel title [text] — Set the stream title', $action->help('/', full: true));
    }

    public function testAQualifierThatIsNotAUsableCommandNameIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        new Action('Not A Name', 'title', static fn () => null);
    }

    public function testAnActionLimitedToAChatCannotAlsoBeASlashCommand(): void
    {
        $this->expectException(\LogicException::class);

        new Action('twitch', 'title', static fn () => null, only: 'twitch', slash: new Slash());
    }

    // ── Collisions ─────────────────────────────────────────────────────

    public function testTwoActionsWithTheSameQualifiedNameAreRefused(): void
    {
        // Silently overwriting would mean whichever registered last wins, with
        // nothing in the log to say so.
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title'));

        $this->expectException(\LogicException::class);

        $registry->add($this->action('twitch', 'title', 'channel'));
    }

    public function testTheSameNameUnderTwoQualifiersIsFine(): void
    {
        // The whole point: `title` belongs to Twitch today and to something
        // else the moment a fourth network arrives.
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title'));
        $registry->add($this->action('telegram', 'title'));

        $this->assertSame(2, $registry->count());
        $this->assertSame(['twitch', 'telegram'], $registry->qualifiers());
    }

    public function testAnAliasCollidingWithinAQualifierIsRefused(): void
    {
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title'));

        $this->expectException(\LogicException::class);

        $registry->add(new Action('twitch', 'game', static fn () => null, aliases: ['title']));
    }

    public function testAConnectorCannotClaimAnotherConnectorsQualifier(): void
    {
        // Otherwise one package can shadow another's commands from outside it.
        $registry = new ActionRegistry();
        $provider = $this->provider([$this->action('telegram', 'send')]);

        $this->expectException(\LogicException::class);

        $registry->addFrom($provider, 'twitch');
    }

    public function testAConnectorsOwnActionsAreAccepted(): void
    {
        $registry = new ActionRegistry();
        $registry->addFrom($this->provider([$this->action('twitch', 'title'), $this->action('twitch', 'game')]), 'twitch');

        $this->assertSame(2, $registry->count());
        $this->assertCount(2, $registry->forQualifier('twitch'));
    }

    // ── Resolving what somebody typed ──────────────────────────────────

    public function testAChatsShortFormResolves(): void
    {
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title', 'channel'));

        [$action, $rest] = $registry->resolve(['twitch', 'title', 'Back', 'in', 'ten']);

        $this->assertSame('twitch title', $action?->key());
        $this->assertSame(['Back', 'in', 'ten'], $rest);
    }

    public function testDiscordsLongFormResolvesToTheSameAction(): void
    {
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title', 'channel'));

        [$action, $rest] = $registry->resolve(['twitch', 'channel', 'title', 'Back']);

        $this->assertSame('twitch title', $action?->key());
        $this->assertSame(['Back'], $rest);
    }

    public function testAnAliasResolvesWithinItsQualifier(): void
    {
        $registry = new ActionRegistry();
        $registry->add(new Action('twitch', 'title', static fn () => null, aliases: ['name']));

        $this->assertSame('twitch title', $registry->resolve(['twitch', 'name'])[0]?->key());
        $this->assertNull($registry->resolve(['telegram', 'name'])[0]);
    }

    public function testAnUnqualifiedCommandResolvesToNothing(): void
    {
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title'));

        $this->assertNull($registry->resolve(['title'])[0]);
        $this->assertNull($registry->resolve(['title', 'Back'])[0]);
    }

    public function testExtraWhitespaceIsNotADifferentCommand(): void
    {
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title'));

        $this->assertTrue($registry->has('TWITCH   title'));
    }

    // ── Listing ────────────────────────────────────────────────────────

    public function testHelpIsGroupedByConnectorFirst(): void
    {
        // With several installed, "which platform is this for" is the first
        // thing a reader needs.
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title', 'channel'));
        $registry->add($this->action('twitch', 'link'));
        $registry->add($this->action('telegram', 'send', 'chat'));

        $this->assertSame(
            ['twitch channel', 'twitch', 'telegram chat'],
            array_keys($registry->grouped(Surface::discord(), Access::Operator)),
        );
    }

    public function testHelpOmitsWhatTheAskerWouldBeRefused(): void
    {
        $registry = new ActionRegistry();
        $registry->add($this->action('twitch', 'title'));
        $registry->add(new Action('twitch', 'ban', static fn () => null, access: Access::Moderator));

        $grouped = $registry->grouped(Surface::discord(), Access::Everyone);

        $this->assertCount(1, $grouped['twitch']);
        $this->assertSame('twitch title', $grouped['twitch'][0]->key());
    }

    public function testAnActionLimitedToOneSurfaceIsNotOfferedOnAnother(): void
    {
        $registry = new ActionRegistry();
        $registry->add(new Action('twitch', 'whisper', static fn () => null, only: 'twitch'));

        $this->assertCount(0, $registry->forSurface(Surface::discord()));
        $this->assertCount(1, $registry->forSurface(new Surface('twitch', 'Twitch', 500)));
    }

    private function action(string $qualifier, string $name, ?string $group = null): Action
    {
        return new Action($qualifier, $name, static fn () => null, group: $group);
    }

    /** @param list<Action> $actions */
    private function provider(array $actions): ProvidesActions
    {
        return new class ($actions) implements ProvidesActions {
            /** @param list<Action> $actions */
            public function __construct(private readonly array $actions)
            {
            }

            public function actions(): array
            {
                return $this->actions;
            }
        };
    }
}

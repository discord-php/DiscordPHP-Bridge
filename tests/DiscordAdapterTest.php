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

use Bridge\Bot;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\DiscordAdapter;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Tests\Doubles\BuildsBot;
use Bridge\Tests\Doubles\FakeConnector;
use Discord\Parts\Channel\Message;
use PHPUnit\Framework\TestCase;

/**
 * Which room a Discord prefix command acts on.
 *
 * The link table is the security boundary: a server's commands reach the rooms
 * that server bridged. Naming another room with `target=` must not step
 * around it, or `ban`, `raid` and `send` reach every room the bot can.
 */
final class DiscordAdapterTest extends TestCase
{
    use BuildsBot;

    private const MINE = '111111111111';

    private const THEIRS = '999999999999';

    private Bot $bot;

    protected function setUp(): void
    {
        $this->bot = $this->bot();
        $this->bot->addConnector(new FakeConnector('alpha', 'Alpha', ['r1', 'r2', 'r3']));

        $store = $this->bot->getStore();
        $store->link('alpha', self::MINE, '222222222222', 'r1');
        $store->link('alpha', self::MINE, '333333333333', 'r2');
        $store->link('alpha', self::THEIRS, '444444444444', 'r3');
    }

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    public function testWithoutAnOverrideItIsThisChannelsRoom(): void
    {
        $this->assertSame('r1', $this->context($this->ban(), 'x')?->target);
    }

    public function testAnotherRoomThisServerBridgedMayBeNamed(): void
    {
        $this->assertSame('r2', $this->context($this->ban(), 'x target=r2')?->target);
        $this->assertSame('r2', $this->context($this->ban(), 'x channel=r2')?->target);
    }

    public function testARoomOnlyAnotherServerBridgedMayNot(): void
    {
        $error = $this->context($this->ban(), 'x target=r3');

        $this->assertInstanceOf(ActionError::class, $error);
        $this->assertStringContainsString('not a Alpha room this server has bridged', $error->getMessage());
    }

    public function testARoomNobodyBridgedMayNot(): void
    {
        $this->assertInstanceOf(ActionError::class, $this->context($this->ban(), 'x target=elsewhere'));
    }

    public function testTheOperatorMayNameAnyRoom(): void
    {
        $this->assertSame('r3', $this->context($this->ban(), 'x target=r3', Access::Operator)?->target);
    }

    public function testAnOptionTheActionDeclaresIsItsOwnArgument(): void
    {
        // `link target=r3` is asking to bridge with r3, not to act on it.
        $link = new Action('alpha', 'connect', static fn () => null, slash: new Slash([
            new SlashOption('target', 'The room to bridge with.', required: true),
        ]));

        $this->assertSame('r1', $this->context($link, 'target=r3')?->target);
    }

    private function ban(): Action
    {
        return new Action('alpha', 'ban', static fn () => null, access: Access::Moderator);
    }

    /** The context the adapter builds, or the reason it refused. */
    private function context(Action $action, string $line, Access $access = Access::Moderator): Context|\Throwable|null
    {
        $message = new Message($this->bot, [
            'id' => '555555555555',
            'channel_id' => '222222222222',
            'guild_id' => self::MINE,
            'content' => '!alpha ban ' . $line,
            'author' => (object) ['id' => '666666666666', 'username' => 'somebody'],
        ]);

        $adapter = new DiscordAdapter($this->bot, $this->bot->getActions());
        $contextFor = new \ReflectionMethod($adapter, 'contextFor');

        $result = null;
        $contextFor->invoke($adapter, $action, $message, $access, Arguments::fromString($line), true)->then(
            static function (Context $context) use (&$result): void {
                $result = $context;
            },
            static function (\Throwable $e) use (&$result): void {
                $result = $e;
            },
        );

        return $result;
    }
}

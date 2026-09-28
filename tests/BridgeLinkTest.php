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

use Bridge\Actions\BridgeActions;
use Bridge\Bot;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Surface;
use Bridge\Tests\Doubles\BuildsBot;
use Bridge\Tests\Doubles\FakeConnector;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use PHPUnit\Framework\TestCase;

/**
 * What `link` tells whoever ran it when the room cannot be bridged: the
 * connector's own reason when it gives one, and never a raw error.
 */
final class BridgeLinkTest extends TestCase
{
    use BuildsBot;

    private const GUILD = '111111111111';

    private const CHANNEL = '222222222222';

    private Bot $bot;

    private FakeConnector $alpha;

    protected function setUp(): void
    {
        $this->alpha = new FakeConnector('alpha', 'Alpha', ['r1']);
        $this->bot = $this->bot();
        $this->bot->addConnector($this->alpha);

        $this->bot->private_channels->pushItem($this->bot->getFactory()->part(Channel::class, [
            'id' => self::CHANNEL,
            'guild_id' => self::GUILD,
            'type' => Channel::TYPE_GUILD_TEXT,
        ], true));
    }

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    public function testARoomIsLinked(): void
    {
        $said = $this->link('r1');

        $this->assertStringContainsString('is now bridged with **R1**', $said);
        $this->assertSame('r1', $this->bot->getStore()->links('alpha')->targetFor(self::CHANNEL));
    }

    public function testAConnectorsReasonForRefusingARoomIsShown(): void
    {
        $this->alpha->refuse = new ActionError('the bot can only bridge its own channel.');

        $this->expectException(ActionError::class);
        $this->expectExceptionMessage('the bot can only bridge its own channel.');

        $this->link('someone-else');
    }

    public function testAnyOtherFailureIsLoggedNotQuoted(): void
    {
        $this->alpha->refuse = new \RuntimeException('GET https://api.example.test/rooms?token=secret timed out');

        try {
            $this->link('r1');
            $this->fail('expected a refusal');
        } catch (ActionError $e) {
            $this->assertSame('could not reach Alpha to check that room — try again in a moment.', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }

        $this->assertNull($this->bot->getStore()->links('alpha')->targetFor(self::CHANNEL), 'nothing was linked');
    }

    /** Runs `/alpha link` in the bridged channel, as a server admin, and returns the reply. */
    private function link(string $target): string
    {
        $action = null;
        foreach ((new BridgeActions($this->alpha))->actions() as $candidate) {
            if ($candidate instanceof Action && $candidate->name === 'link') {
                $action = $candidate;
            }
        }
        $this->assertNotNull($action);

        $message = $this->bot->getFactory()->part(Message::class, [
            'id' => '333333333333',
            'channel_id' => self::CHANNEL,
            'guild_id' => self::GUILD,
            'content' => '!alpha link ' . $target,
        ], true);

        $context = new Context($this->bot, Surface::discord(), Access::Administrator, 'Admin', '5', connector: 'alpha', message: $message);

        $reply = null;
        $failure = null;
        $action->run($context, Arguments::fromParts([$target]))->then(
            static function (string $said) use (&$reply): void {
                $reply = $said;
            },
            static function (\Throwable $e) use (&$failure): void {
                $failure = $e;
            },
        );

        if ($failure !== null) {
            throw $failure;
        }

        return (string) $reply;
    }
}

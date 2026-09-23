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
use Bridge\Command\Action;
use Bridge\Message\Incoming;
use Bridge\Message\Media;
use Bridge\Relay\ChatRelay;
use Bridge\Tests\Doubles\BuildsBot;
use Bridge\Tests\Doubles\FakeConnector;
use PHPUnit\Framework\TestCase;

/**
 * The network-to-network half of the relay, against a real {@see Bot} that
 * never connects.
 *
 * With no gateway there are no cached Discord channels, so the Discord copies
 * are skipped; what is left is exactly the hop Discord cannot make, because
 * its copy of every relayed message is a webhook message it drops.
 */
final class ChatRelayTest extends TestCase
{
    use BuildsBot;

    private const GUILD = '111111111111';

    private const CHANNEL = '222222222222';

    private Bot $bot;

    private FakeConnector $alpha;

    private FakeConnector $beta;

    protected function setUp(): void
    {
        $this->alpha = new FakeConnector('alpha', 'Alpha', ['r1'], [
            new Action('alpha', 'whoami', static fn () => null),
        ]);
        $this->beta = new FakeConnector('beta', 'Beta', ['b1', 'b2']);

        $this->bot = $this->bot();
        $this->bot->addConnector($this->alpha)->addConnector($this->beta);

        (new ChatRelay($this->bot))->attach();
    }

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    public function testAChannelBridgedToTwoNetworksCarriesEachToTheOther(): void
    {
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', '1'));

        $this->assertCount(1, $this->beta->relayed);
        $this->assertSame('b1', $this->beta->relayed[0]['target']);
        $this->assertSame('Somebody (alpha)', $this->beta->relayed[0]['message']->author);
        $this->assertSame('hello', $this->beta->relayed[0]['message']->text);
    }

    public function testNothingIsSentBackToWhereItCameFrom(): void
    {
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', '1'));

        $this->assertSame([], $this->alpha->relayed);
    }

    public function testEachRoomHearsItOnceHoweverManyChannelsLeadThere(): void
    {
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('alpha', '333333333333', 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');
        $this->bridge('beta', '333333333333', 'b1');
        $this->bridge('beta', '444444444444', 'b2');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', '1'));

        // b2 is bridged, but not to anywhere r1 is.
        $this->assertSame(['b1'], array_column($this->beta->relayed, 'target'));
    }

    public function testTheBotsOwnVoiceIsNeverRelayed(): void
    {
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Bridge', 'bot', 'relayed already', '1', own: true));

        $this->assertSame([], $this->beta->relayed);
    }

    public function testCommandsAreAnsweredNotBroadcast(): void
    {
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', '!alpha whoami', '1'));
        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'yes!!!', '2'));

        $this->assertSame(['yes!!!'], array_map(
            static fn (array $sent): string => $sent['message']->text,
            $this->beta->relayed,
        ));
    }

    public function testAnEditTheOtherNetworkCannotApplyIsNotPostedTwice(): void
    {
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'helo', '1'));
        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', '1', edited: true));

        $this->assertCount(1, $this->beta->relayed);
    }

    public function testAFileOnlyItsOwnNetworkCanOpenIsNamedNotLinked(): void
    {
        // A Telegram photo: an id, and no URL that does not carry the token.
        $this->bridge('alpha', self::CHANNEL, 'r1');
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'look', '1', media: [
            new Media(Media::IMAGE, null, 'file-id', 'cat.jpg'),
        ]));

        $message = $this->beta->relayed[0]['message'];

        $this->assertSame('look 📎 cat.jpg', $message->text);
        $this->assertSame([], $message->media);
    }

    public function testAnUnbridgedRoomGoesNowhere(): void
    {
        $this->bridge('beta', self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', '1'));

        $this->assertSame([], $this->beta->relayed);
    }

    private function bridge(string $connector, string $channel, string $target): void
    {
        $this->bot->getStore()->link($connector, self::GUILD, $channel, $target);
    }
}

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
use Bridge\Message\Incoming;
use Bridge\Relay\ChatRelay;
use Bridge\Tests\Doubles\BuildsBot;
use Bridge\Tests\Doubles\FakeConnector;
use Bridge\Tests\Doubles\RecordingDelivery;
use Discord\Parts\Channel\Channel;
use PHPUnit\Framework\TestCase;

/**
 * A message deleted on its network, or an author banned there, takes the
 * Discord copies with it, and nothing else.
 */
final class ChatRelayDeletionTest extends TestCase
{
    use BuildsBot;

    private const GUILD = '111111111111';

    private const CHANNEL = '222222222222';

    private const OTHER_CHANNEL = '333333333333';

    private Bot $bot;

    private FakeConnector $alpha;

    private FakeConnector $beta;

    private RecordingDelivery $delivery;

    protected function setUp(): void
    {
        $this->alpha = new FakeConnector('alpha', 'Alpha', ['r1']);
        $this->beta = new FakeConnector('beta', 'Beta', ['b1']);
        $this->delivery = new RecordingDelivery();

        $this->bot = $this->bot();
        $this->bot->addConnector($this->alpha)->addConnector($this->beta);

        // The channels, as the gateway would have cached them.
        foreach ([self::CHANNEL, self::OTHER_CHANNEL] as $id) {
            $this->bot->private_channels->pushItem($this->bot->getFactory()->part(Channel::class, [
                'id' => $id,
                'type' => Channel::TYPE_GUILD_TEXT,
            ], true));
        }

        $this->bot->getStore()->link('alpha', self::GUILD, self::CHANNEL, 'r1');
        $this->bot->getStore()->link('alpha', self::GUILD, self::OTHER_CHANNEL, 'r1');

        (new ChatRelay($this->bot, delivery: $this->delivery))->attach();
    }

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    public function testEveryDiscordCopyOfADeletedMessageGoes(): void
    {
        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'buy followers at spam.example', 'm1'));
        $this->assertCount(2, $this->delivery->delivered, 'one copy in each bridged channel');

        $this->alpha->receive(Incoming::deletion('r1', 'm1'));

        $this->assertSame([
            ['channel' => self::CHANNEL, 'message_id' => 'd1', 'via' => 'webhook'],
            ['channel' => self::OTHER_CHANNEL, 'message_id' => 'd2', 'via' => 'webhook'],
        ], $this->delivery->deleted);
    }

    public function testABanTakesTheAuthorsRecentMessagesAndNobodyElses(): void
    {
        $this->bot->getStore()->unlink('alpha', self::GUILD, self::OTHER_CHANNEL);

        $this->alpha->receive(new Incoming('r1', 'Spammer', 'u1', 'one', 'm1'));
        $this->alpha->receive(new Incoming('r1', 'Regular', 'u2', 'hello', 'm2'));
        $this->alpha->receive(new Incoming('r1', 'Spammer', 'u1', 'two', 'm3'));

        $this->alpha->receive(Incoming::purge('r1', 'u1', 'Spammer'));

        $this->assertSame(['d1', 'd3'], array_column($this->delivery->deleted, 'message_id'));
    }

    public function testADeletionIsAppliedOnce(): void
    {
        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'oops', 'm1'));

        $this->alpha->receive(Incoming::deletion('r1', 'm1'));
        $this->alpha->receive(Incoming::deletion('r1', 'm1'));
        $this->alpha->receive(Incoming::purge('r1', 'u1'));

        $this->assertCount(2, $this->delivery->deleted, 'the two copies, once each');
    }

    public function testSomethingNeverRelayedHasNothingToDelete(): void
    {
        $this->alpha->receive(Incoming::deletion('r1', 'never-seen'));
        $this->alpha->receive(Incoming::purge('r1', 'nobody'));

        $this->assertSame([], $this->delivery->deleted);
        $this->assertSame([], $this->delivery->delivered, 'and nothing is posted either');
    }

    public function testTheCopyOnAnotherNetworkIsLeftAlone(): void
    {
        $this->bot->getStore()->link('beta', self::GUILD, self::CHANNEL, 'b1');

        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', 'm1'));
        $this->alpha->receive(Incoming::deletion('r1', 'm1'));

        $this->assertCount(1, $this->beta->relayed, 'relayed to the other network once, and not unsaid there');
        $this->assertCount(2, $this->delivery->deleted);
    }

    public function testAMessageOfTheBotsOwnIsNotDeletedByADeletionOfItsOwn(): void
    {
        $this->alpha->receive(new Incoming('r1', 'Somebody', 'u1', 'hello', 'm1'));

        $this->alpha->receive(new Incoming('r1', '', id: 'm1', own: true, deleted: true));

        $this->assertSame([], $this->delivery->deleted);
    }
}

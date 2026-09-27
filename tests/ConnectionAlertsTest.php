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
use Bridge\Support\ConnectionAlerts;
use Bridge\Tests\Doubles\BuildsBot;
use Bridge\Tests\Doubles\FakeConnector;
use Bridge\Tests\Doubles\ReconnectingConnector;
use Discord\Builders\MessageBuilder;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * The owner hears about a connection that will not come back by itself, gets
 * a button to try again, and sees the same DM change when it does come back.
 */
final class ConnectionAlertsTest extends TestCase
{
    use BuildsBot;

    private const OWNER = '1000';

    private Bot $bot;

    private ReconnectingConnector $network;

    private ConnectionAlerts $alerts;

    /** @var list<RecordingMessage> Every DM sent, oldest first. */
    private array $dms = [];

    /** Whether an owner is configured, as far as sending is concerned. */
    private bool $hasOwner = true;

    protected function setUp(): void
    {
        $this->bot = $this->bot(['DISCORD_OWNER_ID' => self::OWNER]);
        $this->network = new ReconnectingConnector();
        $this->bot->addConnector($this->network);

        $this->alerts = new ConnectionAlerts($this->bot, function (MessageBuilder $message): PromiseInterface {
            if (! $this->hasOwner) {
                return resolve(null);
            }

            return resolve($this->dms[] = new RecordingMessage($message));
        });
    }

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    public function testTheBotRoutesTheButton(): void
    {
        $this->assertTrue($this->bot->components()->handles(ConnectionAlerts::RECONNECT));
    }

    public function testAFailureIsDmedOncePerOutageWithAButton(): void
    {
        $this->alerts->failed($this->network, 'It tried 10 times.', 1_700_000_000);
        $this->alerts->failed($this->network, 'It tried 11 times.');

        $this->assertCount(1, $this->dms);
        $dm = $this->dms[0]->json();
        $this->assertStringContainsString('Network is disconnected', $dm);
        $this->assertStringContainsString('<t:1700000000:t>', $dm);
        $this->assertStringContainsString('It tried 10 times.', $dm);
        $this->assertStringContainsString('bridge:reconnect:network', $dm);
        $this->assertStringContainsString('Reconnect now', $dm);
        $this->assertTrue($this->alerts->isAlerting('network'));
    }

    public function testComingBackEditsTheDmOnce(): void
    {
        $this->alerts->failed($this->network);
        $this->alerts->restored($this->network);
        $this->alerts->restored($this->network);

        $this->assertCount(1, $this->dms[0]->edits);
        $this->assertStringContainsString('Network is back', $this->dms[0]->edits[0]);
        $this->assertStringContainsString('reconnected by itself', $this->dms[0]->edits[0]);
        $this->assertStringNotContainsString('bridge:reconnect', $this->dms[0]->edits[0], 'the button goes');
        $this->assertFalse($this->alerts->isAlerting('network'));
    }

    public function testComingBackWithoutADmIsQuiet(): void
    {
        $this->alerts->restored($this->network);

        $this->assertSame([], $this->dms);
    }

    public function testWithNoOwnerNothingIsKept(): void
    {
        $this->hasOwner = false;
        $this->alerts->failed($this->network);

        $this->assertFalse($this->alerts->isAlerting('network'));
    }

    public function testThePressedButtonReconnectsAndSaysSo(): void
    {
        $this->alerts->failed($this->network);
        $press = new RecordingInteraction(self::OWNER, $this->dms[0]);

        $this->alerts->pressed($press, 'network');

        $this->assertSame(1, $this->network->reconnects);
        $this->assertStringContainsString('Reconnecting to Network', $press->updates[0]);
        $this->assertStringContainsString('Network is back', $press->originalUpdates[0]);
        $this->assertStringNotContainsString('by itself', $press->originalUpdates[0]);

        // The DM is already up to date; the connection coming back does not edit it again.
        $this->alerts->restored($this->network);
        $this->assertSame([], $this->dms[0]->edits);
    }

    public function testAFailedPressKeepsTheButtonAndTheErrorOutOfTheDm(): void
    {
        $this->network->reconnectFails = true;
        $this->alerts->failed($this->network);
        $press = new RecordingInteraction(self::OWNER, $this->dms[0]);

        $this->alerts->pressed($press, 'network');

        $shown = $press->originalUpdates[0];
        $this->assertStringContainsString('That attempt failed too.', $shown);
        $this->assertStringContainsString('bridge:reconnect:network', $shown, 'the button is offered again');
        $this->assertStringNotContainsString('secret.example', $shown, 'the reason stays in the log');

        // It is still down, and when it comes back by itself the same DM says so.
        $this->assertTrue($this->alerts->isAlerting('network'));
        $this->alerts->restored($this->network);
        $this->assertStringContainsString('reconnected by itself', $this->dms[0]->edits[0]);
    }

    public function testOnlyTheOwnerCanPressIt(): void
    {
        $this->alerts->failed($this->network);
        $press = new RecordingInteraction('2000', $this->dms[0]);

        $this->alerts->pressed($press, 'network');

        $this->assertSame(0, $this->network->reconnects);
        $this->assertTrue($press->ephemeral);
        $this->assertStringContainsString('limited to', $press->responses[0]);
    }

    public function testAConnectorThatCannotReconnectSaysSo(): void
    {
        $this->bot->addConnector(new FakeConnector('fake', 'Fake'));
        $press = new RecordingInteraction(self::OWNER);

        $this->alerts->pressed($press, 'fake');

        $this->assertStringContainsString('Fake cannot be reconnected from here.', $press->updates[0]);
    }
}

/** A sent DM: what it said, and every edit made to it. */
final class RecordingMessage
{
    /** @var list<string> */
    public array $edits = [];

    public function __construct(private readonly MessageBuilder $sent)
    {
    }

    public function json(): string
    {
        return (string) json_encode($this->sent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function edit(MessageBuilder $message): PromiseInterface
    {
        $this->edits[] = (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return resolve($this);
    }
}

/** A button press in a DM, recording how it was answered. */
final class RecordingInteraction
{
    public object $user;

    public ?object $guild = null;

    /** @var list<string> */
    public array $updates = [];

    /** @var list<string> */
    public array $originalUpdates = [];

    /** @var list<string> */
    public array $responses = [];

    public bool $ephemeral = false;

    public function __construct(string $userId, public ?RecordingMessage $message = null)
    {
        $this->user = (object) ['id' => $userId];
    }

    public function updateMessage(MessageBuilder $message): PromiseInterface
    {
        $this->updates[] = (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return resolve(null);
    }

    public function updateOriginalResponse(MessageBuilder $message): PromiseInterface
    {
        $this->originalUpdates[] = (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return resolve(null);
    }

    public function respondWithMessage(MessageBuilder|string $message, bool $ephemeral = false): PromiseInterface
    {
        $this->responses[] = is_string($message) ? $message : (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->ephemeral = $ephemeral;

        return resolve(null);
    }
}

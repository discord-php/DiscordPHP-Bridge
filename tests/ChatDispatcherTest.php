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
use Bridge\Builders\PanelBuilder;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\ChatDispatcher;
use Bridge\Command\ChatInvocation;
use Bridge\Command\Context;
use Bridge\Command\Surface;
use Bridge\Room;
use Bridge\Tests\Doubles\BuildsBot;
use Bridge\Tests\Doubles\FakeConnector;
use PHPUnit\Framework\TestCase;

/**
 * Commands typed in a connector's own chat, against a real {@see Bot} that
 * never connects.
 *
 * Two fake networks, `alpha` and `beta`, stand in for Twitch and Telegram:
 * what matters here is the routing between them, not either network.
 */
final class ChatDispatcherTest extends TestCase
{
    use BuildsBot;

    private const GUILD = '111111111111';

    /** @var list<Context> */
    private array $contexts = [];

    /** @var list<string> */
    private array $replies = [];

    private Bot $bot;

    private FakeConnector $alpha;

    private FakeConnector $beta;

    protected function setUp(): void
    {
        $record = function (Context $context): string {
            $this->contexts[] = $context;

            return 'ran on ' . ($context->target ?? 'nothing');
        };

        $this->alpha = new FakeConnector('alpha', 'Alpha', ['r1', 'r2'], [
            new Action('alpha', 'whoami', $record),
            new Action('alpha', 'title', $record, group: 'channel'),
            new Action('alpha', 'ban', $record, access: Access::Moderator),
            new Action('alpha', 'token', $record, sensitive: true),
            new Action('alpha', 'panel', static fn () => PanelBuilder::notice('hi')),
            new Action('alpha', 'refuse', static fn () => throw new ActionError('no such user.')),
            new Action('alpha', 'crash', static fn () => throw new \RuntimeException('https://api.example/bot123:SECRET/x')),
            new Action('alpha', 'clip', $record, cooldown: 30),
            new Action('alpha', 'setup', $record, only: Surface::DISCORD),
        ]);

        $this->beta = new FakeConnector('beta', 'Beta', ['b1', 'b2'], [
            new Action('beta', 'send', $record),
        ]);

        $this->bot = $this->bot();
        $this->bot->addConnector($this->alpha)->addConnector($this->beta);
    }

    protected function tearDown(): void
    {
        $this->removeBotDir();
    }

    // ── What counts as a command ───────────────────────────────────────

    public function testOnlyRegisteredCommandsCount(): void
    {
        $dispatcher = new ChatDispatcher($this->bot, $this->alpha);

        $this->assertTrue($dispatcher->isCommand('!alpha whoami'));
        $this->assertTrue($dispatcher->isCommand('  !ALPHA whoami  '));
        $this->assertTrue($dispatcher->isCommand('!beta send hi'));

        // Ordinary chat, and other bots' commands, stay ordinary chat.
        $this->assertFalse($dispatcher->isCommand('yes!!!'));
        $this->assertFalse($dispatcher->isCommand('!drop'));
        $this->assertFalse($dispatcher->isCommand('!alpha nonsense'));
        $this->assertFalse($dispatcher->isCommand('alpha whoami'));
        $this->assertFalse($dispatcher->isCommand('!'));
    }

    public function testNothingIsUnqualified(): void
    {
        // `whoami` is only ever `alpha whoami`, however unambiguous it looks.
        $this->assertFalse((new ChatDispatcher($this->bot, $this->alpha))->isCommand('!whoami'));
    }

    public function testABareQualifierListsWhatCanBeRunHere(): void
    {
        $dispatcher = new ChatDispatcher($this->bot, $this->alpha);

        $this->assertTrue($dispatcher->isCommand('!alpha'));
        $this->assertTrue($this->dispatch('!alpha'));

        $list = $this->replies[0] ?? '';

        $this->assertStringStartsWith('!alpha: ', $list);
        $this->assertStringContainsString('whoami', $list);
        // Nothing the asker may not run, and nothing that only works on Discord.
        $this->assertStringNotContainsString('ban', $list);
        $this->assertStringNotContainsString('setup', $list);
    }

    // ── Where it acts ──────────────────────────────────────────────────

    public function testItsOwnNetworksCommandsActOnTheRoomTheyWereTypedIn(): void
    {
        $this->dispatch('!alpha whoami', room: 'r1', roomId: '9001');

        $this->assertSame(['ran on r1'], $this->replies);
        $this->assertSame('alpha', $this->contexts[0]->connector);
        $this->assertSame('r1', $this->contexts[0]->target);
        $this->assertSame('9001', $this->contexts[0]->targetId);
    }

    public function testTheGroupIsDroppedInChatButStillAccepted(): void
    {
        $this->dispatch('!alpha title');
        $this->dispatch('!alpha channel title');

        $this->assertCount(2, $this->contexts);
    }

    public function testAnotherNetworksCommandActsOnTheRoomBridgedThroughDiscord(): void
    {
        // r1 on alpha ⇄ Discord channel ⇄ b1 on beta.
        $this->bot->getStore()->link('alpha', self::GUILD, '222222222222', 'r1');
        $this->bot->getStore()->link('beta', self::GUILD, '222222222222', 'b1');

        $this->dispatch('!beta send hi', room: 'r1');

        $this->assertSame(['ran on b1'], $this->replies);
        $this->assertSame('beta', $this->contexts[0]->connector);
        $this->assertSame('b1', $this->contexts[0]->target);
        $this->assertSame('b1', $this->contexts[0]->targetId);
    }

    public function testAnAmbiguousBridgeIsNeverGuessed(): void
    {
        // r1 feeds two channels bridged to two different beta rooms. Posting
        // into either would be a guess, and possibly the wrong community.
        $store = $this->bot->getStore();
        $store->link('alpha', self::GUILD, '222222222222', 'r1');
        $store->link('alpha', self::GUILD, '333333333333', 'r1');
        $store->link('beta', self::GUILD, '222222222222', 'b1');
        $store->link('beta', self::GUILD, '333333333333', 'b2');

        $this->dispatch('!beta send hi', room: 'r1');

        $this->assertNull($this->contexts[0]->target);
    }

    public function testTwoChannelsBridgedToTheSameRoomAreNotAmbiguous(): void
    {
        $store = $this->bot->getStore();
        $store->link('alpha', self::GUILD, '222222222222', 'r1');
        $store->link('alpha', self::GUILD, '333333333333', 'r1');
        $store->link('beta', self::GUILD, '222222222222', 'b1');
        $store->link('beta', self::GUILD, '333333333333', 'b1');

        $this->dispatch('!beta send hi', room: 'r1');

        $this->assertSame('b1', $this->contexts[0]->target);
    }

    public function testAnUnbridgedRoomGivesAnotherNetworkNothingToActOn(): void
    {
        $this->dispatch('!beta send hi', room: 'r2');

        $this->assertNull($this->contexts[0]->target);
    }

    public function testTheCoresOwnCommandsActOnNoRoom(): void
    {
        $dispatcher = new ChatDispatcher($this->bot, $this->alpha);
        $core = new Action('bridge', 'about', static fn () => null);

        $context = null;
        $dispatcher->contextFor($core, $this->who())->then(function (Context $c) use (&$context): void {
            $context = $c;
        });

        $this->assertInstanceOf(Context::class, $context);
        $this->assertNull($context->connector);
        $this->assertNull($context->target);
    }

    // ── Refusals ───────────────────────────────────────────────────────

    public function testADiscordOnlyCommandSaysWhereToRunIt(): void
    {
        $this->dispatch('!alpha setup');

        $this->assertSame([], $this->contexts);
        $this->assertStringContainsString('/alpha setup', $this->replies[0] ?? '');
    }

    public function testTheRungIsChecked(): void
    {
        $this->dispatch('!alpha ban someone');
        $this->assertSame([], $this->contexts);
        $this->assertStringContainsString('limited to', $this->replies[0] ?? '');

        $this->replies = [];
        $this->dispatch('!alpha ban someone', access: Access::Moderator);
        $this->assertCount(1, $this->contexts);
    }

    public function testASensitiveAnswerIsNeverPostedInChat(): void
    {
        // Not even for the operator: the chat is public whoever asked.
        $this->dispatch('!alpha token', access: Access::Operator);

        $this->assertSame([], $this->contexts);
        $this->assertStringContainsString('only be run from Discord', $this->replies[0] ?? '');
    }

    public function testACooldownIsSilentAndPerPerson(): void
    {
        $this->dispatch('!alpha clip', invokerId: 'u1');
        $this->assertTrue($this->dispatch('!alpha clip', invokerId: 'u1'));
        $this->dispatch('!alpha clip', invokerId: 'u2');

        // The repeat was swallowed as a command but not run, and not answered.
        $this->assertCount(2, $this->contexts);
        $this->assertCount(2, $this->replies);
    }

    // ── Answers ────────────────────────────────────────────────────────

    public function testAnActionErrorIsSaidAsIs(): void
    {
        $this->dispatch('!alpha refuse');

        $this->assertSame(['no such user.'], $this->replies);
    }

    public function testAnythingElseIsSummarisedRatherThanLeaked(): void
    {
        // The message could be a URL with a token in it.
        $this->dispatch('!alpha crash');

        $this->assertSame(['that did not work.'], $this->replies);
    }

    public function testAPanelIsNotRenderedBadlyInChat(): void
    {
        $this->dispatch('!alpha panel');

        $this->assertSame(['that answer can only be shown on Discord.'], $this->replies);
    }

    // ── Rooms ──────────────────────────────────────────────────────────

    public function testARoomsApiIdFallsBackToItsRoutingKey(): void
    {
        $this->assertSame('coffeescrafts', (new Room('coffeescrafts', 'Coffee'))->apiId());
        $this->assertSame('12345', (new Room('coffeescrafts', 'Coffee', platformId: '12345'))->apiId());
    }

    private function dispatch(
        string $text,
        string $room = 'r1',
        ?string $roomId = null,
        Access $access = Access::Everyone,
        string $invokerId = 'u1',
    ): bool {
        return (new ChatDispatcher($this->bot, $this->alpha))->dispatch(
            $text,
            $this->who($room, $roomId, $access, $invokerId),
            function (string $answer): void {
                $this->replies[] = $answer;
            },
        );
    }

    private function who(
        string $room = 'r1',
        ?string $roomId = null,
        Access $access = Access::Everyone,
        string $invokerId = 'u1',
    ): ChatInvocation {
        return new ChatInvocation($room, $roomId, 'Somebody', $invokerId, $access);
    }
}

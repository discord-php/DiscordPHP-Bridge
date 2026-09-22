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

use Bridge\Actions\BridgeActions;
use Bridge\Actions\CoreActions;
use Bridge\Command\Action;
use Bridge\Command\ActionRegistry;
use Bridge\Command\Surface;
use Bridge\Tests\Doubles\FakeConnector;
use PHPUnit\Framework\TestCase;

/**
 * The shape two connectors and the core produce together, without a gateway.
 *
 * Discord caps a command at 25 options and allows one level of sub-command
 * group, so the tree is not merely a naming convention — exceeding it means
 * Discord rejects the whole command and a connector loses every one of its.
 */
final class CommandTreeTest extends TestCase
{
    public function testEveryConnectorGetsTheSameSixBridgeVerbs(): void
    {
        // Defined once, so `/twitch link` and `/telegram link` cannot drift.
        $registry = $this->registry();

        foreach (['link', 'here', 'unlink', 'list', 'status', 'reset'] as $verb) {
            $this->assertTrue($registry->has('twitch ' . $verb), "twitch {$verb} is missing");
            $this->assertTrue($registry->has('telegram ' . $verb), "telegram {$verb} is missing");
        }
    }

    public function testTwoConnectorsWithTheSameCommandNamesDoNotCollide(): void
    {
        // Both bring `ban`, and both keep it.
        $registry = $this->registry();

        $this->assertTrue($registry->has('twitch ban'));
        $this->assertTrue($registry->has('telegram ban'));
        $this->assertNotSame($registry->get('twitch ban'), $registry->get('telegram ban'));
    }

    public function testTheCoreOwnsBridgeAndNobodyElseDoes(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->has('bridge help'));
        $this->assertTrue($registry->has('bridge about'));
        $this->assertSame(['bridge', 'twitch', 'telegram'], $registry->qualifiers());
    }

    public function testNothingIsReachableWithoutItsQualifier(): void
    {
        $registry = $this->registry();

        foreach (['link', 'ban', 'help', 'title'] as $bare) {
            $this->assertNull($registry->resolve([$bare])[0], "{$bare} should not resolve on its own");
        }
    }

    public function testEachCommandFitsInsideDiscordsOptionCap(): void
    {
        $registry = $this->registry();
        $counts = [];
        $groups = [];

        foreach ($registry->forSurface(Surface::discord()) as $action) {
            if ($action->slash === null) {
                continue;
            }

            // Sub-commands and groups both count against the command's 25.
            $slot = $action->group ?? $action->name;
            $counts[$action->qualifier][$slot] = true;
            $groups[$action->qualifier][$action->group ?? ''][$action->name] = true;
        }

        foreach ($counts as $qualifier => $slots) {
            $this->assertLessThanOrEqual(25, count($slots), "/{$qualifier} declares too many options");
        }

        foreach ($groups as $qualifier => $byGroup) {
            foreach ($byGroup as $group => $names) {
                $this->assertLessThanOrEqual(25, count($names), "/{$qualifier} {$group} declares too many sub-commands");
            }
        }
    }

    public function testAGroupedCommandIsReachableBothWays(): void
    {
        $registry = $this->registry();

        // Discord sends the long form; a chat sends the short one.
        $this->assertSame('twitch title', $registry->resolve(['twitch', 'channel', 'title'])[0]?->key());
        $this->assertSame('twitch title', $registry->resolve(['twitch', 'title'])[0]?->key());
    }

    public function testTheBridgeVerbsAreDiscordOnlyExceptStatus(): void
    {
        // Configuring which Discord channel goes on stream is not something to
        // do from the other end of the bridge.
        $registry = $this->registry();
        $chat = new Surface('twitch', 'Twitch', 500);

        $this->assertFalse($registry->get('twitch link')?->availableOn($chat));
        $this->assertFalse($registry->get('twitch reset')?->availableOn($chat));
        $this->assertTrue($registry->get('twitch status')?->availableOn($chat));
    }

    /** The core plus two connectors that both bring a `ban`. */
    private function registry(): ActionRegistry
    {
        $registry = new ActionRegistry();
        $registry->addAll(new CoreActions());

        foreach ([['twitch', 'Twitch'], ['telegram', 'Telegram']] as [$name, $label]) {
            $connector = new FakeConnector($name, $label, ['somewhere'], [
                new Action($name, 'ban', static fn () => null, 'Ban somebody', group: 'mod'),
                new Action($name, 'title', static fn () => null, 'Set the title', group: 'channel'),
            ]);

            $registry->addAll(new BridgeActions($connector));
            $registry->addFrom($connector, $name);
        }

        return $registry;
    }
}

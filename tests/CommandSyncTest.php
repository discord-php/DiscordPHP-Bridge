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

use Bridge\Support\CommandSync;
use PHPUnit\Framework\TestCase;

/**
 * A restart must publish a command whose definition changed in code, and must
 * not republish one that only *looks* different because Discord echoed it back
 * with its own fields and defaults.
 *
 * Both halves matter. A bot with two connectors installed publishes a command
 * tree two platforms wide, so republishing all of it every boot is a stack of
 * rate-limited writes to say nothing; and one that instead skips whatever is
 * already registered leaves each command frozen in the shape it had the first
 * time it was published.
 */
final class CommandSyncTest extends TestCase
{
    public function testAnUnchangedCommandIsNotRepublished(): void
    {
        $this->assertFalse(CommandSync::differs($this->published(), $this->built()));
    }

    public function testDiscordsOwnFieldsAreIgnored(): void
    {
        $published = $this->published() + [
            'id' => '123456789',
            'application_id' => '987654321',
            'version' => '111',
            'dm_permission' => true,
            'guild_id' => null,
        ];

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testKeyOrderIsNotAChange(): void
    {
        $this->assertFalse(CommandSync::differs(array_reverse($this->published(), true), $this->built()));
    }

    public function testAnOmittedFalseIsTheSameAsAnExplicitOne(): void
    {
        // Discord drops a false `required` rather than sending it back.
        $published = $this->published();
        unset($published['options'][1]['options'][1]['required']);
        unset($published['nsfw']);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testAnOmittedTypeMeansChatInput(): void
    {
        $published = $this->published();
        unset($published['type']);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testContextsAndIntegrationTypesAreSets(): void
    {
        $built = $this->built();
        $built['contexts'] = [0];
        $built['integration_types'] = [0];

        $published = $this->published();
        $published['contexts'] = [0];
        $published['integration_types'] = [0];

        $this->assertFalse(CommandSync::differs($published, $built));

        // Same members, different order: still the same command.
        $published['contexts'] = [1, 0];
        $built['contexts'] = [0, 1];

        $this->assertFalse(CommandSync::differs($published, $built));
    }

    public function testANewSubCommandIsAChange(): void
    {
        // The case the whole class exists for: `twitch reset` added in code,
        // never offered by Discord unless this returns true.
        $built = $this->built();
        $built['options'][] = ['type' => 1, 'name' => 'reset', 'description' => 'Clear every bridge on this server.'];

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testARewordedDescriptionIsAChange(): void
    {
        $built = $this->built();
        $built['description'] = 'Bridge this server with Twitch. Manage Server only.';

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testAnOptionBecomingRequiredIsAChange(): void
    {
        $built = $this->built();
        $built['options'][1]['options'][1]['required'] = true;

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testReorderedSubCommandsAreAChange(): void
    {
        // Sub-commands are shown in the order they were declared, so order is
        // part of the definition, unlike contexts.
        $built = $this->built();
        $built['options'] = array_reverse($built['options']);

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testObjectShapedPayloadsAreHandled(): void
    {
        // A Part or a raw gateway payload arrives as objects, not arrays.
        $published = json_decode((string) json_encode($this->published()));

        $this->assertFalse(CommandSync::differs((array) $published, $this->built()));
    }

    // ── Removing what this build no longer defines ─────────────────────

    public function testACommandThisBuildStillDefinesIsSpared(): void
    {
        $this->assertSame([], CommandSync::stale(
            [['name' => 'twitch'], ['name' => 'telegram']],
            ['twitch', 'telegram'],
        ));
    }

    public function testACommandThatWasRenamedAwayIsReportedAsStale(): void
    {
        // Publishing /twitch leaves the /relay it replaced sitting in every
        // server's command list, pointing at a handler that is gone.
        $this->assertSame(['relay', 'title'], CommandSync::stale(
            [['name' => 'twitch'], ['name' => 'relay'], ['name' => 'title']],
            ['twitch'],
        ));
    }

    public function testStalenessIgnoresCase(): void
    {
        $this->assertSame([], CommandSync::stale([['name' => 'Twitch']], ['twitch']));
    }

    public function testPublishedCommandsMayArriveAsObjects(): void
    {
        $published = [
            json_decode((string) json_encode(['name' => 'relay'])),
            json_decode((string) json_encode(['name' => 'twitch'])),
        ];

        $this->assertSame(['relay'], CommandSync::stale($published, ['twitch']));
    }

    public function testNothingDeclaredMeansEverythingIsStale(): void
    {
        // Which is exactly why the caller may only run this once every
        // connector has started: an incomplete list would wipe the lot.
        $this->assertSame(['telegram', 'twitch'], CommandSync::stale(
            [['name' => 'twitch'], ['name' => 'telegram']],
            [],
        ));
    }

    /** What Discord has: the same command, echoed back with its own fields. */
    private function published(): array
    {
        return $this->built() + ['id' => '1', 'application_id' => '2', 'version' => '3'];
    }

    /** What this build defines. */
    private function built(): array
    {
        return [
            'type' => 1,
            'name' => 'twitch',
            'description' => 'Bridge this server with Twitch.',
            'contexts' => [0],
            'integration_types' => [0],
            'nsfw' => false,
            'options' => [
                ['type' => 1, 'name' => 'list', 'description' => 'Show every bridge configured on this server.'],
                [
                    'type' => 1,
                    'name' => 'link',
                    'description' => 'Bridge a Discord channel with a Twitch channel.',
                    'options' => [
                        ['type' => 3, 'name' => 'target', 'description' => 'The Twitch channel to follow.', 'required' => true],
                        ['type' => 7, 'name' => 'channel', 'description' => 'The Discord channel to bridge.', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}

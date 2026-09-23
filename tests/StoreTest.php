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

use Bridge\Store;
use Bridge\Support\Filesystem;
use Bridge\Tests\Doubles\DeferredAdapter;
use PHPUnit\Framework\TestCase;

/**
 * What a server configured has to be there when the bot comes back, and has to
 * survive the file being damaged, hand-edited, or written by an older build.
 */
final class StoreTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-store-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/bridges.json';
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    // ── The basics ─────────────────────────────────────────────────────

    public function testABridgeSurvivesARestart(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'coffeescrafts');
        $store->flush();

        $this->assertSame('coffeescrafts', $this->store()->links('twitch')->targetFor('c1'));
    }

    public function testAChannelPointsAtOneTargetPerConnector(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'first');
        $store->link('twitch', 'g1', 'c1', 'second');

        $this->assertSame('second', $store->links('twitch')->targetFor('c1'));
        $this->assertSame(1, $store->count());
    }

    public function testOneChannelCanBridgeTwoPlatformsAtOnce(): void
    {
        // The reason bridges are grouped by connector at all.
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'coffeescrafts');
        $store->link('telegram', 'g1', 'c1', '-1001234567890');

        $this->assertSame('coffeescrafts', $store->links('twitch')->targetFor('c1'));
        $this->assertSame('-1001234567890', $store->links('telegram')->targetFor('c1'));
        $this->assertSame(['telegram', 'twitch'], $store->connectors());
        $this->assertSame(2, $store->count());
    }

    public function testUnlinkingOneConnectorLeavesTheOther(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'coffeescrafts');
        $store->link('telegram', 'g1', 'c1', '-100');
        $store->unlink('twitch', 'g1', 'c1');

        $this->assertNull($store->links('twitch')->targetFor('c1'));
        $this->assertSame('-100', $store->links('telegram')->targetFor('c1'));
        $this->assertSame(['telegram'], $store->connectors());
    }

    public function testUnlinkingSomethingUnbridgedChangesNothing(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'x');
        $before = $store->toArray();

        $store->unlink('twitch', 'g1', 'c9');

        $this->assertSame($before, $store->toArray());
    }

    public function testResettingAServerLeavesOtherServersAlone(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'a');
        $store->link('twitch', 'g2', 'c2', 'b');
        $store->forgetGuild('twitch', 'g1');

        $this->assertSame([], $store->links('twitch')->forGuild('g1'));
        $this->assertSame(['c2' => 'b'], $store->links('twitch')->forGuild('g2'));
    }

    public function testClearingAnOverrideSurvivesRatherThanComingBack(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'a');
        $store->flush();

        $reopened = $this->store();
        $reopened->unlink('twitch', 'g1', 'c1');
        $reopened->flush();

        $this->assertTrue($this->store()->links('twitch')->isEmpty());
    }

    // ── Labels ─────────────────────────────────────────────────────────

    public function testALabelIsRememberedSoAListingNeedNotAsk(): void
    {
        $store = $this->store();
        $store->link('telegram', 'g1', 'c1', '-100', 'My Group');
        $store->flush();

        $this->assertSame('My Group', $this->store()->label('telegram', '-100'));
    }

    public function testALabelIsForgottenOnceNothingPointsAtIt(): void
    {
        // Otherwise the file grows a name for every chat ever linked.
        $store = $this->store();
        $store->link('telegram', 'g1', 'c1', '-100', 'My Group');
        $store->unlink('telegram', 'g1', 'c1');

        $this->assertNull($store->label('telegram', '-100'));
    }

    public function testALabelIsKeptWhileAnotherServerStillFollowsIt(): void
    {
        $store = $this->store();
        $store->link('telegram', 'g1', 'c1', '-100', 'My Group');
        $store->link('telegram', 'g2', 'c2', '-100');
        $store->unlink('telegram', 'g1', 'c1');

        $this->assertSame('My Group', $store->label('telegram', '-100'));
    }

    // ── Migration ──────────────────────────────────────────────────────

    public function testAFileFromTheSinglePlatformBotIsMigratedInPlace(): void
    {
        // Exactly what DiscordPHP-TwitchBot writes today. Nothing should have
        // to be re-run for these bridges to keep working.
        file_put_contents($this->path, (string) json_encode([
            'links' => ['1547425733851353088' => ['1549845016887951550' => 'coffeescrafts']],
        ]));

        $store = $this->store();

        $this->assertTrue($store->migrated());
        $this->assertSame(['twitch'], $store->connectors());
        $this->assertSame('coffeescrafts', $store->links('twitch')->targetFor('1549845016887951550'));
        $this->assertSame([], $store->warnings());
    }

    public function testTheMigratedFileIsWrittenBackInTheCurrentShape(): void
    {
        file_put_contents($this->path, (string) json_encode(['links' => ['111111111111' => ['c1' => 'x']]]));

        $store = $this->store();
        $store->link('twitch', '111111111111', 'c2', 'y');
        $store->flush();

        /** @var array<string, mixed> $written */
        $written = json_decode((string) file_get_contents($this->path), true);

        $this->assertSame(Store::VERSION, $written['version']);
        $this->assertSame(['c1' => 'x', 'c2' => 'y'], $written['links']['twitch']['111111111111']);
    }

    public function testWhichConnectorAnOldFileBelongsToIsTheCallersToSay(): void
    {
        file_put_contents($this->path, (string) json_encode(['links' => ['111111111111' => ['c1' => '-100']]]));

        $store = new Store($this->path, Filesystem::blocking(), 'telegram');

        $this->assertSame(['telegram'], $store->connectors());
    }

    public function testTheTelegramBotsFileIsRecognisedByItsTitles(): void
    {
        // DiscordPHP-TelegramRelay's file, which the app never names as the
        // legacy connector: the titles table is what gives it away.
        file_put_contents($this->path, (string) json_encode([
            'links' => ['111111111111' => ['222222222222' => '-1001234567890']],
            'titles' => ['-1001234567890' => 'My Group'],
        ]));

        $store = $this->store();

        $this->assertTrue($store->migrated());
        $this->assertSame(['telegram'], $store->connectors());
        $this->assertSame('-1001234567890', $store->links('telegram')->targetFor('222222222222'));
        $this->assertSame('My Group', $store->label('telegram', '-1001234567890'));
    }

    public function testACurrentFileMissingItsVersionIsNotBuriedALevelDeeper(): void
    {
        // Keyed by connector name, so it is already the current shape: wrapping
        // it under "twitch" would make "twitch" a guild id.
        file_put_contents($this->path, (string) json_encode([
            'links' => ['twitch' => ['111111111111' => ['222222222222' => 'coffeescrafts']]],
        ]));

        $store = $this->store();

        $this->assertFalse($store->migrated());
        $this->assertSame(['twitch'], $store->connectors());
        $this->assertSame('coffeescrafts', $store->links('twitch')->targetFor('222222222222'));
    }

    public function testALabelForSomethingUnbridgedIsNotWritten(): void
    {
        $adapter = new DeferredAdapter();
        $store = new Store($this->path, Filesystem::with($adapter, 'deferred'));

        // Every message from an unbridged group names it; none of them should
        // cost a write.
        $store->rememberLabel('telegram', '-100', 'Somebody else');
        $store->rememberLabel('telegram', '-100', 'Somebody else');

        $this->assertNull($store->label('telegram', '-100'));
        $this->assertSame(0, $adapter->pending());
    }

    public function testACurrentFileIsNotMigratedAgain(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'x');
        $store->flush();

        $this->assertFalse($this->store()->migrated());
    }

    public function testAnEmptyFileIsNotMistakenForAnOldOne(): void
    {
        file_put_contents($this->path, (string) json_encode(['links' => []]));

        $this->assertFalse($this->store()->migrated());
    }

    // ── A file somebody edited ─────────────────────────────────────────

    public function testEntriesOfTheWrongShapeAreDroppedAndReported(): void
    {
        file_put_contents($this->path, (string) json_encode([
            'version' => 2,
            'links' => [
                'twitch' => [
                    'g1' => ['c1' => 'good', 'c2' => ['not', 'a', 'target']],
                    'g2' => 'not a list of channels',
                ],
            ],
        ]));

        $store = $this->store();

        $this->assertSame(['c1' => 'good'], $store->links('twitch')->forGuild('g1'));
        $this->assertCount(2, $store->warnings());
        $this->assertStringContainsString('channel c2', $store->warnings()[0]);
        $this->assertStringContainsString('server g2', $store->warnings()[1]);
    }

    public function testTheGoodEntriesInAHandEditedFileStillSurviveTheNextWrite(): void
    {
        file_put_contents($this->path, (string) json_encode([
            'version' => 2,
            'links' => ['twitch' => ['g1' => ['c1' => 'good', 'c2' => []]]],
        ]));

        $store = $this->store();
        $store->link('twitch', 'g1', 'c3', 'also good');
        $store->flush();

        $this->assertSame(['c1' => 'good', 'c3' => 'also good'], $this->store()->links('twitch')->forGuild('g1'));
    }

    public function testADamagedFileIsRecoveredFromTheBackup(): void
    {
        $store = $this->store();
        $store->link('twitch', 'g1', 'c1', 'coffeescrafts');
        $store->flush();

        // Truncated by something outside this process.
        file_put_contents($this->path, '{"version": 2, "links": {"twi');

        $recovered = $this->store();

        $this->assertSame('coffeescrafts', $recovered->links('twitch')->targetFor('c1'));
        $this->assertStringContainsString('recovered', $recovered->warnings()[0] ?? '');
    }

    public function testAnUnreadableFileIsKeptRatherThanOverwritten(): void
    {
        // One truncated file plus one `link` would otherwise be every server's
        // bridges gone, with nothing left to recover from.
        file_put_contents($this->path, 'not json');
        file_put_contents($this->path . '.bak', 'not json either');

        $store = $this->store();

        $this->assertSame([], $store->connectors());
        $this->assertStringContainsString('kept as', $store->warnings()[0] ?? '');

        $store->link('twitch', 'g1', 'c1', 'x');
        $store->flush();

        $kept = glob($this->dir . '/bridges.json.corrupt-*') ?: [];

        $this->assertCount(1, $kept);
        $this->assertSame('not json', file_get_contents($kept[0]));
    }

    // ── Off the loop ───────────────────────────────────────────────────

    public function testLinkingDoesNotWaitForTheDisk(): void
    {
        $adapter = new DeferredAdapter();
        $store = new Store($this->path, Filesystem::with($adapter, 'deferred'));

        $store->link('twitch', 'g1', 'c1', 'x');

        // Answered from memory; nothing has reached the disk yet.
        $this->assertSame('x', $store->links('twitch')->targetFor('c1'));
        $this->assertFileDoesNotExist($this->path);
        $this->assertSame(1, $adapter->pending());

        $adapter->settle();

        $this->assertFileExists($this->path);
    }

    public function testABurstOfChangesCollapsesIntoOneFollowUpWrite(): void
    {
        $adapter = new DeferredAdapter();
        $store = new Store($this->path, Filesystem::with($adapter, 'deferred'));

        $store->link('twitch', 'g1', 'c1', 'a');   // starts a write
        $store->link('twitch', 'g1', 'c2', 'b');   // queued behind it
        $store->link('twitch', 'g1', 'c3', 'c');   // folded into the same follow-up

        $adapter->settle();

        // Two rounds of (file + backup), not three, and the newest state wins.
        $this->assertSame(4, $adapter->countOf('write'));
        $this->assertSame(
            ['c1' => 'a', 'c2' => 'b', 'c3' => 'c'],
            $this->store()->links('twitch')->forGuild('g1'),
        );
    }

    public function testSavedResolvesOnceTheDiskHasCaughtUp(): void
    {
        $adapter = new DeferredAdapter();
        $store = new Store($this->path, Filesystem::with($adapter, 'deferred'));

        $store->link('twitch', 'g1', 'c1', 'a');
        $store->link('twitch', 'g1', 'c2', 'b');

        $done = false;
        $store->saved()->then(function () use (&$done): void {
            $done = true;
        });

        $this->assertFalse($done);

        $adapter->settle();

        $this->assertTrue($done);
    }

    public function testFlushWritesEvenWithAQueuedWriteOutstanding(): void
    {
        // Shutdown: the loop is about to stop, so a queued write would never
        // run and the change somebody just made would be the one lost.
        $adapter = new DeferredAdapter();
        $store = new Store($this->path, Filesystem::with($adapter, 'deferred'));

        $store->link('twitch', 'g1', 'c1', 'a');

        $this->assertFileDoesNotExist($this->path);
        $this->assertTrue($store->flush());
        $this->assertSame('a', $this->store()->links('twitch')->targetFor('c1'));
    }

    private function store(): Store
    {
        return new Store($this->path, Filesystem::blocking());
    }
}

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

namespace Bridge;

use Bridge\Actions\BridgeActions;
use Bridge\Actions\CoreActions;
use Bridge\Builders\PanelBuilder;
use Bridge\Capability\ProvidesActions;
use Bridge\Capability\ProvidesModules;
use Bridge\Command\ActionRegistry;
use Bridge\Command\Cooldowns;
use Bridge\Command\DiscordAdapter;
use Bridge\Command\SlashAdapter;
use Bridge\Helpers\ComponentRouter;
use Bridge\Modules\Module;
use Bridge\Relay\ChatRelay;
use Bridge\Relay\OutboundPacer;
use Bridge\Relay\WebhookDelivery;
use Bridge\Support\BridgeCheck;
use Bridge\Support\CommandSync;
use Bridge\Support\InstallCheck;
use Discord\MessageCommandClient;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Interactions\Interaction;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Discord\WebSockets\Event as DiscordEvent;
use Discord\WebSockets\Intents;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * The bot: one Discord gateway connection, one HTTP client, and however many
 * connectors the application installed.
 *
 * Extending `MessageCommandClient` rather than owning one is what lets the
 * Discord side keep everything that class already provides — prefix handling,
 * aliases, cooldowns, the command registry — while each connector brings the
 * equivalent for its own network. None of them know about each other: they are
 * all fed from one {@see ActionRegistry}, and a command written once appears in
 * every chat.
 *
 * ## One client, deliberately
 *
 * There is exactly one `Discord\Http` in the process, built by `Discord`
 * itself, and every request to Discord goes through it — including the relay's
 * webhook executions, which are `Webhook::execute()` and therefore already
 * routed there. That object holds the per-route rate-limit buckets and the
 * concurrency cap, so a second client would keep its own empty bucket table and
 * race the first into 429s against the same token. Connectors are forbidden to
 * build one; see {@see Connector}.
 *
 * What the buckets cannot do is decline to send. Merging two bots into one
 * halved the budget — two applications with two tokens became one — so relayed
 * traffic is additionally paced per destination channel by
 * {@see OutboundPacer}, leaving room for the commands somebody is waiting on.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
class Bot extends MessageCommandClient
{
    /** Where this lives. Printed into chat, so it has to resolve. */
    public const GITHUB = 'https://github.com/discord-php/DiscordPHP-Bridge';

    /** MESSAGE_CONTENT is privileged; without it every relayed message is empty. */
    public const INTENTS = Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::MESSAGE_CONTENT;

    /**
     * How long after startup to check the restored bridges.
     *
     * Long enough for the guild caches to fill and the joins to land.
     */
    public const BRIDGE_CHECK_DELAY = 10.0;

    private readonly ActionRegistry $actions;

    private readonly ComponentRouter $components;

    private readonly OutboundPacer $pacer;

    private ?WebhookDelivery $delivery = null;

    private ?ChatRelay $relay = null;

    /** @var array<string, Connector> keyed by {@see Connector::name()} */
    private array $connectors = [];

    /** @var list<Module> */
    private array $modules = [];

    private bool $modulesBooted = false;

    private bool $started = false;

    /** @var array<string, bool> connector name => whether it started */
    private array $up = [];

    private readonly Cooldowns $cooldowns;

    /** Every top-level slash command this build defines; see {@see Support\CommandSync}. */
    private array $declared = [];

    /** The last bridge check's headline. */
    private ?string $lastCheck = null;

    /** @var list<string> Trouble found before there was anywhere to report it. */
    private array $startupWarnings = [];

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        array $options = [],
    ) {
        parent::__construct($options + [
            'token' => $config->discordToken,
            'intents' => self::INTENTS,
            'prefix' => $config->discordPrefix,
            'caseInsensitiveCommands' => true,
            // Replaced by the bridge's own `help`, which lists what the asker
            // may actually run and is worded the same on every surface.
            'defaultHelpCommand' => false,
        ]);

        $this->actions = new ActionRegistry();
        $this->components = new ComponentRouter();
        $this->pacer = new OutboundPacer($this->getLoop());
        $this->cooldowns = new Cooldowns();

        // The core's own commands, under the one qualifier no connector may
        // claim. Registered first so a connector that tried would collide here
        // rather than silently shadowing them.
        $this->actions->addAll(new CoreActions());

        // Components v2 buttons all come back as one INTERACTION_CREATE, and
        // every one of them carries what it means in its custom_id — so there
        // is one listener rather than one per button, and a panel posted by a
        // build that has since restarted still works.
        $this->on(DiscordEvent::INTERACTION_CREATE, function (Interaction $interaction): void {
            $this->components->dispatch($interaction);
        });

        // Every panel that offers a way out offers this one.
        $this->components->on('dismiss', static fn (Interaction $interaction) => $interaction->updateMessage(
            PanelBuilder::notice('Cancelled.'),
        ));

        // The second press behind every connector's `reset`.
        $this->components->on(
            BridgeActions::CONFIRM_RESET,
            fn (Interaction $interaction, array $args) => BridgeActions::confirmed($this, $interaction, (string) ($args[0] ?? '')),
        );

        // Who can add the bot is decided in the Developer Portal, and nothing
        // there warns when it is left open. DiscordPHP fetches the settings
        // once connected; read them back and say what is wrong.
        $this->once('application-init', function (): void {
            foreach (InstallCheck::review($this->application) as $finding) {
                $this->logger->log($finding['level'], '[bridge] ' . $finding['message']);
            }
        });

        $this->addStartupWarnings('bridge configuration', $store->warnings());

        $this->once('init', fn () => $this->start());
    }

    // ── Wiring ─────────────────────────────────────────────────────────

    /**
     * Installs a connector.
     *
     * Booted straight away rather than at `ready`, because whatever it
     * registers has to be in place before the first message arrives; it is
     * *connected* later, in {@see start()}.
     */
    public function addConnector(Connector $connector): self
    {
        $name = $connector->name();

        if (isset($this->connectors[$name])) {
            throw new \LogicException("A connector named {$name} is already installed.");
        }

        if ($name === CoreActions::QUALIFIER) {
            throw new \LogicException(sprintf("'%s' is the core's own qualifier and cannot be a connector.", $name));
        }

        $this->connectors[$name] = $connector;
        $connector->boot($this);

        // Every connector gets link/here/unlink/list/status/reset, defined once
        // so they cannot drift apart between networks.
        $this->actions->addAll(new BridgeActions($connector));

        if ($connector instanceof ProvidesActions) {
            $this->actions->addFrom($connector, $name);
        }

        if ($connector instanceof ProvidesModules) {
            foreach ($connector->modules() as $module) {
                $this->addModule($module);
            }
        }

        return $this;
    }

    public function addModule(Module $module): self
    {
        $this->modules[] = $module;

        if ($this->modulesBooted) {
            $module->boot($this);
        }

        return $this;
    }

    /** Records a top-level command name this build defines, so the prune pass spares it. */
    public function declareCommand(string $name): void
    {
        $this->declared[strtolower($name)] = true;
    }

    /** @return list<string> */
    public function declaredCommands(): array
    {
        return array_keys($this->declared);
    }

    // ── Accessors ──────────────────────────────────────────────────────

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    public function getActions(): ActionRegistry
    {
        return $this->actions;
    }

    public function components(): ComponentRouter
    {
        return $this->components;
    }

    public function pacer(): OutboundPacer
    {
        return $this->pacer;
    }

    /** One clock for every surface, so a cooldown cannot be dodged by switching chats. */
    public function cooldowns(): Cooldowns
    {
        return $this->cooldowns;
    }

    public function delivery(): WebhookDelivery
    {
        return $this->delivery ??= new WebhookDelivery($this, $this->pacer, $this->config->webhookName);
    }

    /** One connector by name, or `null` when it is not installed. */
    public function connector(string $name): ?Connector
    {
        return $this->connectors[$name] ?? null;
    }

    /** @return array<string, Connector> */
    public function connectors(): array
    {
        return $this->connectors;
    }

    /** @return list<Module> */
    public function modules(): array
    {
        return $this->modules;
    }

    // ── Bridges ────────────────────────────────────────────────────────

    /**
     * Brings one connector's memberships in line with the routing table. Safe
     * to call before it has connected; it becomes a no-op there.
     */
    public function sync(string $connector): void
    {
        $client = $this->connector($connector);

        if ($client === null) {
            return;
        }

        $links = $this->store->links($connector);
        $changed = $client->sync($links);

        if ($changed['join'] !== [] || $changed['part'] !== []) {
            $this->logger->info(sprintf(
                '[bridge] %s synced (+%d / -%d), now following %d',
                $connector,
                count($changed['join']),
                count($changed['part']),
                count($links->targets()),
            ));
        }
    }

    /** What the last bridge check found. */
    public function rememberCheck(string $summary): void
    {
        $this->lastCheck = $summary;
    }

    /**
     * The last bridge check's headline, or `null` before it has run.
     *
     * Worth showing next to the bridges themselves: one whose Discord channel
     * or remote room went away while the bot was down reads exactly like a
     * working one from a listing alone.
     */
    public function getLastCheck(): ?string
    {
        return $this->lastCheck;
    }

    /**
     * Records trouble found before the bot had a log or an owner to report it
     * to — reading a damaged store, most obviously.
     *
     * @param list<string> $warnings
     */
    public function addStartupWarnings(string $what, array $warnings): void
    {
        foreach ($warnings as $warning) {
            $this->startupWarnings[] = sprintf('%s: %s', $what, $warning);
        }
    }

    /**
     * Tells a human, when one is configured, rather than only the log.
     *
     * A bot that needs attention and only whispers it into a logfile stays
     * broken until somebody happens to look.
     */
    public function notifyOwner(string $markdown): void
    {
        $ownerId = $this->config->discordOwnerId;

        if ($ownerId === null || $ownerId === '') {
            return;
        }

        $this->users->fetch($ownerId)->then(
            fn ($user) => $user->sendMessage($markdown),
        )->then(null, fn (\Throwable $e) => $this->logger->error(
            '[bridge] could not DM the owner: ' . $e->getMessage(),
        ));
    }

    /**
     * Flushes state and disconnects every connector.
     *
     * For a signal handler: once the loop stops a queued write would never run,
     * and the change somebody just made would be the one lost.
     */
    public function shutdown(): void
    {
        $this->logger->info('[bridge] shutting down');
        $this->store->flush();

        foreach ($this->connectors as $connector) {
            try {
                $connector->stop();
            } catch (\Throwable $e) {
                $this->logger->warning(sprintf('[bridge] %s did not stop cleanly: %s', $connector->name(), $e->getMessage()));
            }
        }

        $this->close();
    }

    // ── Startup ────────────────────────────────────────────────────────

    /**
     * Wires up Discord once it is ready, then connects every connector.
     *
     * Discord's half goes first and does not wait. The catalogue is complete
     * the moment the connectors are installed — each adds its actions then — so
     * there is nothing to wait for, and waiting would let a network that is
     * slow to come up, or never does, hold Discord's commands hostage. An
     * operator has to be able to ask what went wrong from somewhere.
     *
     * Each connector then starts on its own. One that fails does not take the
     * others with it; its rooms are simply never joined, and the bridge check
     * says so.
     */
    private function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        (new DiscordAdapter($this, $this->actions))->register();
        (new SlashAdapter($this, $this->actions))->register();

        $this->bootModules();

        // Listeners only. Nothing arrives from a connector until it starts,
        // and they are all in place before any of them do.
        $this->relay = new ChatRelay($this);
        $this->relay->attach();

        $this->logger->info(sprintf(
            '[bridge] %d action(s) across %d connector(s)',
            $this->actions->count(),
            count($this->connectors),
        ));

        $this->reportRestoredBridges();

        $starting = [];

        foreach ($this->connectors as $name => $connector) {
            $starting[$name] = $this->startConnector($name, $connector);
        }

        ($starting === [] ? resolve([]) : all($starting))->then(function (array $up): void {
            $this->pruneCommands(! in_array(false, $up, true));

            if ($this->store->count() > 0) {
                // Late enough that the guild caches have settled and the joins
                // have landed; probing any earlier reports working bridges as
                // broken.
                $this->getLoop()->addTimer(self::BRIDGE_CHECK_DELAY, fn () => $this->verifyBridges());
            }
        });
    }

    /**
     * Starts one connector, then joins its rooms.
     *
     * Joining waits for the start rather than racing it: a connector cannot
     * join anything before it has a connection to join with, and one that
     * tried would report every room as missing.
     *
     * @return PromiseInterface<bool> Whether it came up. Never rejects.
     */
    private function startConnector(string $name, Connector $connector): PromiseInterface
    {
        try {
            $starting = $connector->start();
        } catch (\Throwable $e) {
            $starting = reject($e);
        }

        return $starting->then(
            function () use ($name): bool {
                $this->up[$name] = true;
                $this->sync($name);

                return true;
            },
            function (\Throwable $e) use ($name, $connector): bool {
                $this->up[$name] = false;

                $this->logger->error(sprintf('[bridge] %s failed to start: %s', $name, $e->getMessage()));
                $this->logger->warning(sprintf('[bridge] running without %s; its bridges and commands are unavailable', $name));

                // The reason stays in the log. An exception from a network
                // library can quote a request, and a DM is not the place to
                // find out whether this one did.
                $this->notifyOwner(sprintf(
                    "⚠️ **%s did not start**, so its bridges are not relaying. The reason is in the bot's log.",
                    $connector->label(),
                ));

                return false;
            },
        );
    }

    /** Whether a connector has started and not failed. */
    public function isUp(string $name): bool
    {
        return $this->up[$name] ?? false;
    }

    /**
     * Removes global commands this build no longer defines.
     *
     * Renaming a command is two operations and only one of them is obvious:
     * publishing `/twitch` leaves the `/relay` it replaced sitting in every
     * server's command list, pointing at a handler that is gone. That reads as
     * a broken bot rather than a renamed one.
     *
     * Only ever run when **every** connector started. One that failed to boot
     * never declared its commands, and pruning against an incomplete list would
     * delete the working commands of whichever package happened to be unlucky —
     * a far worse outcome than leaving a stale name for one restart.
     */
    private function pruneCommands(bool $everyConnectorStarted): void
    {
        if (! $everyConnectorStarted) {
            $this->logger->info('[bridge] not removing stale commands: something did not start, so the list is incomplete');

            return;
        }

        if ($this->application === null) {
            return;
        }

        $this->application->commands->freshen()->then(
            function (GlobalCommandRepository $repo): void {
                $stale = CommandSync::stale($repo, $this->declaredCommands());

                if ($stale === []) {
                    $this->logger->debug('[bridge] no stale commands registered');

                    return;
                }

                foreach ($stale as $name) {
                    $command = $repo->get('name', $name);

                    if ($command === null) {
                        continue;
                    }

                    $repo->delete($command, 'no longer defined by this build')->then(
                        fn () => $this->logger->info('[bridge] removed stale command /' . $name),
                        fn (\Throwable $e) => $this->logger->error(
                            '[bridge] could not remove stale command /' . $name . ': ' . $e->getMessage(),
                        ),
                    );
                }
            },
            fn (\Throwable $e) => $this->logger->warning(
                '[bridge] could not read registered commands, so none were removed: ' . $e->getMessage(),
            ),
        );
    }

    private function bootModules(): void
    {
        if ($this->modulesBooted) {
            return;
        }

        $this->modulesBooted = true;

        foreach ($this->modules as $module) {
            try {
                $module->boot($this);
                $this->logger->debug('[bridge] booted module ' . $module->name());
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[bridge] module %s failed to boot: %s', $module->name(), $e->getMessage()));
            }
        }
    }

    /**
     * Reports what was restored from disk, and whether it still works.
     *
     * Configuration outlives the process, and both ends of a bridge can stop
     * working while the bot is down — a Discord channel deleted, the bot
     * removed from the server, a room renamed. None of that produces an error
     * at startup; it produces a bridge that quietly relays nothing, which looks
     * exactly like a quiet day.
     */
    private function reportRestoredBridges(): void
    {
        $this->logger->info('[bridge] ' . BridgeCheck::restored(
            $this->store->count(),
            count($this->guildsWithBridges()),
            $this->store->path(),
            $this->store->connectors(),
        ));

        // Whether saves actually happen off the loop is a property of the host,
        // not of this build, so it is worth one line at every start.
        $this->logger->info('[bridge] ' . $this->store->filesystem()->describe());

        if ($this->store->migrated()) {
            $this->logger->info('[bridge] migrated a configuration written by an earlier, single-platform build');
        }

        if ($this->startupWarnings !== []) {
            foreach ($this->startupWarnings as $warning) {
                $this->logger->warning('[bridge] ' . $warning);
            }

            $this->notifyOwner(
                "⚠️ **I had trouble reading my own configuration.**\n- "
                . implode("\n- ", $this->startupWarnings),
            );
        }
    }

    /**
     * Probes every restored bridge, then says what is wrong with which.
     *
     * A connector that is down is reported once, as itself, rather than as a
     * dozen separate bridges that each "cannot hear their room" — the dozen
     * would be true and would hide the one thing worth fixing. Bridges for a
     * connector that is not installed at all are reported the same way: they
     * are kept, but nothing is relaying them.
     */
    private function verifyBridges(): void
    {
        $probes = [];
        $unrelayed = [];

        foreach ($this->store->connectors() as $name) {
            if (! isset($this->connectors[$name])) {
                $unrelayed[] = sprintf(
                    '%d %s bridge(s) are configured, but that connector is not installed — they are kept, and nothing is relaying them.',
                    $this->store->links($name)->count(),
                    $name,
                );
            }
        }

        foreach ($this->connectors as $name => $connector) {
            if (! $this->isUp($name)) {
                if (! $this->store->links($name)->isEmpty()) {
                    $unrelayed[] = sprintf(
                        '%s is not connected, so its %d bridge(s) are not relaying.',
                        $connector->label(),
                        $this->store->links($name)->count(),
                    );
                }

                continue;
            }

            foreach ($this->store->links($name)->targets() as $target) {
                $probes[$name . "\0" . $target] = $connector->resolve($target)->then(
                    static fn (?Room $room): bool => $room !== null,
                    // A lookup that failed is not proof the room is gone.
                    static fn (): bool => true,
                );
            }
        }

        ($probes === [] ? resolve([]) : all($probes))->then(function (array $exists) use ($unrelayed): void {
            $rows = [];

            foreach ($this->connectors as $name => $connector) {
                if (! $this->isUp($name)) {
                    continue;
                }

                $links = $this->store->links($name);
                $joined = $connector->joined();

                foreach ($links->guilds() as $guildId) {
                    foreach ($links->forGuild($guildId) as $channelId => $target) {
                        $rows[] = [
                            'connector' => $name,
                            'channel_id' => (string) $channelId,
                            'target' => (string) $target,
                            'channel_ok' => $this->getChannel($channelId) instanceof Channel,
                            'target_ok' => $exists[$name . "\0" . $target] ?? true,
                            'joined' => in_array((string) $target, $joined, true),
                        ];
                    }
                }
            }

            $summary = BridgeCheck::summarise($rows);
            $summary['problems'] = [...$unrelayed, ...$summary['problems']];
            $total = $this->store->count();

            $headline = sprintf(
                '%d of %d bridge%s working',
                $summary['healthy'],
                $total,
                $total === 1 ? '' : 's',
            );

            $this->rememberCheck($headline);

            if ($summary['problems'] === []) {
                $this->logger->info('[bridge] ' . $headline);

                return;
            }

            $this->logger->warning('[bridge] ' . $headline);

            foreach ($summary['problems'] as $problem) {
                $this->logger->warning('[bridge] ' . $problem);
            }

            // Nothing is pruned: a guild can be briefly unavailable during an
            // outage, and deleting somebody's configuration over a bad ten
            // seconds is far worse than telling them about it.
            $this->notifyOwner(
                sprintf("⚠️ **%s.**\n- ", $headline) . implode("\n- ", $summary['problems'])
                . "\n-# Nothing has been removed — use `list` to fix or unlink these.",
            );
        });
    }

    /**
     * Every guild with a bridge on any connector.
     *
     * @return list<string>
     */
    private function guildsWithBridges(): array
    {
        $guilds = [];

        foreach ($this->store->connectors() as $connector) {
            foreach ($this->store->links($connector)->guilds() as $guildId) {
                $guilds[$guildId] = true;
            }
        }

        return array_keys($guilds);
    }
}

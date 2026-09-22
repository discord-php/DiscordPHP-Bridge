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
use Bridge\Command\DiscordAdapter;
use Bridge\Command\SlashAdapter;
use Bridge\Helpers\ComponentRouter;
use Bridge\Modules\Module;
use Bridge\Relay\ChatRelay;
use Bridge\Relay\OutboundPacer;
use Bridge\Relay\WebhookDelivery;
use Bridge\Support\BridgeCheck;
use Bridge\Support\CommandSync;
use Discord\MessageCommandClient;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Interactions\Interaction;
use Discord\Repository\Interaction\GlobalCommandRepository;
use Discord\WebSockets\Event as DiscordEvent;
use Discord\WebSockets\Intents;

use function React\Promise\all;
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
     * Connects every connector once Discord is ready, then wires up everything
     * that needs them.
     *
     * A connector that fails to start does not take the others with it, and
     * does not stop the Discord half registering: an operator has to be able to
     * ask what went wrong from somewhere.
     */
    private function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;

        $starting = [];

        foreach ($this->connectors as $name => $connector) {
            $starting[$name] = resolve(null)->then(static function () use ($connector): bool {
                $connector->start();

                return true;
            })->then(null, function (\Throwable $e) use ($name): bool {
                $this->logger->error(sprintf('[bridge] %s failed to start: %s', $name, $e->getMessage()));
                $this->logger->warning(sprintf('[bridge] running without %s; its bridges and commands are unavailable', $name));

                return false;
            });
        }

        ($starting === [] ? resolve([]) : all($starting))->then(function (array $up): void {
            // The catalogue is complete once every connector has had its turn,
            // so the adapters see all of it and publish one command per
            // qualifier rather than one per connector boot.
            (new DiscordAdapter($this, $this->actions))->register();
            (new SlashAdapter($this, $this->actions))->register();

            $this->bootModules();

            $this->relay = new ChatRelay($this);
            $this->relay->attach();

            foreach (array_keys(array_filter($up)) as $name) {
                $this->sync((string) $name);
            }

            $this->logger->info(sprintf(
                '[bridge] %d action(s) across %d connector(s)',
                $this->actions->count(),
                count(array_filter($up)),
            ));

            $this->pruneCommands($up === [] || ! in_array(false, $up, true));
            $this->reportRestoredBridges();
        });
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

        if ($this->store->count() === 0) {
            return;
        }

        // Late enough that the guild caches have settled and the joins have had
        // their chance; probing immediately would report both ends as broken.
        $this->getLoop()->addTimer(self::BRIDGE_CHECK_DELAY, fn () => $this->verifyBridges());
    }

    /** Probes every restored bridge, then says what is wrong with which. */
    private function verifyBridges(): void
    {
        $probes = [];

        foreach ($this->connectors as $name => $connector) {
            foreach ($this->store->links($name)->targets() as $target) {
                $probes[$name . "\0" . $target] = $connector->resolve($target)->then(
                    static fn (?Room $room): bool => $room !== null,
                    // A lookup that failed is not proof the room is gone.
                    static fn (): bool => true,
                );
            }
        }

        ($probes === [] ? resolve([]) : all($probes))->then(function (array $exists): void {
            $rows = [];

            foreach ($this->connectors as $name => $connector) {
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
            $headline = sprintf(
                '%d of %d bridge%s working',
                $summary['healthy'],
                count($rows),
                count($rows) === 1 ? '' : 's',
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

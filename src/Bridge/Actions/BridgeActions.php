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

namespace Bridge\Actions;

use Bridge\Bot;
use Bridge\Builders\PanelBuilder;
use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Connector;
use Bridge\Helpers\ComponentRouter;
use Bridge\Room;
use Bridge\Support\Format;
use Bridge\Support\Permissions;
use Discord\Parts\Interactions\Interaction;
use React\Promise\PromiseInterface;

/**
 * The six commands every connector gets: `link`, `here`, `unlink`, `list`,
 * `status` and `reset`.
 *
 * Defined once, under whichever connector they are constructed for, so
 * `/twitch link` and `/telegram link` do the same thing in the same words and
 * cannot drift apart — and a network nobody has written a package for yet
 * arrives with its configuration commands already done.
 *
 * ## The permission gate is the security model
 *
 * All six are limited to whoever the server belongs to, and that is the whole
 * security model of the project: whoever can run `link` decides which Discord
 * channel gets copied into a public chat somewhere else. Point it at a private
 * channel and that channel is on stream.
 *
 * Replies are ephemeral — configuration is nobody else's business, and it keeps
 * the channel clean.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class BridgeActions implements ProvidesActions
{
    /** The component action the `reset` confirmation button carries. */
    public const CONFIRM_RESET = 'reset';

    public function __construct(private readonly Connector $connector)
    {
    }

    /** @return list<Action> */
    public function actions(): array
    {
        $name = $this->connector->name();
        $label = $this->connector->label();
        $ephemeral = new Slash(ephemeral: true);

        return [
            new Action(
                $name,
                'link',
                $this->link(...),
                sprintf('Bridge a Discord channel with a %s room', $label),
                '<room> [#channel]',
                access: Access::Administrator,
                only: 'discord',
                slash: new Slash([
                    new SlashOption('target', sprintf('The %s room to follow.', $label), required: true),
                    new SlashOption('channel', 'The Discord channel to bridge. Defaults to this one.', SlashOption::CHANNEL),
                ], ephemeral: true),
            ),
            new Action(
                $name,
                'here',
                $this->here(...),
                sprintf('Bridge *this* channel with a %s room', $label),
                '<room>',
                access: Access::Administrator,
                only: 'discord',
                slash: new Slash([
                    new SlashOption('target', sprintf('The %s room to follow.', $label), required: true),
                ], ephemeral: true),
            ),
            new Action(
                $name,
                'unlink',
                $this->unlink(...),
                'Stop bridging a Discord channel',
                '[#channel]',
                access: Access::Administrator,
                only: 'discord',
                slash: new Slash([
                    new SlashOption('channel', 'The Discord channel to unbridge. Defaults to this one.', SlashOption::CHANNEL),
                ], ephemeral: true),
            ),
            new Action(
                $name,
                'list',
                $this->list(...),
                'Show what this server has bridged',
                access: Access::Administrator,
                only: 'discord',
                slash: $ephemeral,
            ),
            new Action(
                $name,
                'status',
                $this->status(...),
                'Show what this channel is bridged to, and whether it works',
                slash: $ephemeral,
            ),
            new Action(
                $name,
                'reset',
                $this->reset(...),
                'Clear every bridge on this server',
                access: Access::Administrator,
                only: 'discord',
                slash: $ephemeral,
            ),
        ];
    }

    // ── Handlers ───────────────────────────────────────────────────────

    private function link(Context $context, Arguments $arguments): PromiseInterface
    {
        $guildId = $context->requireGuild();
        $channelId = $this->channel($context, $arguments, 1);
        $this->requireChannelInGuild($context, $channelId, $guildId);
        $target = $this->target($arguments->named('target') ?? $arguments->get(0));

        return $this->connector->resolve($target)->then(
            function (?Room $room) use ($context, $guildId, $channelId, $target): string {
                if ($room === null) {
                    // A typo otherwise produces a bridge that silently never
                    // works: the bot joins a room that isn't there and never
                    // hears anything.
                    throw new ActionError(sprintf(
                        'there is no %s room called `%s`. Check the spelling — a bridge to a room that does not exist looks exactly like one that is simply quiet.',
                        $this->connector->label(),
                        $target,
                    ));
                }

                $context->bot->getStore()->link(
                    $this->connector->name(),
                    $guildId,
                    $channelId,
                    $room->id,
                    $room->label,
                );

                $context->bot->sync($this->connector->name());

                return sprintf(
                    '<#%s> is now bridged with **%s**%s.%s',
                    $channelId,
                    $room->label,
                    $room->url === null ? '' : ' (' . $room->url . ')',
                    $this->permissionNote($context, $channelId),
                );
            },
            function (\Throwable $e) use ($context, $target): never {
                // Logged, not quoted. The reply is public, and an error from
                // another network's client is not guaranteed to be free of
                // the request that caused it.
                $context->bot->getLogger()->warning(sprintf(
                    '[%s] could not look up %s to link it: %s',
                    $this->connector->name(),
                    $target,
                    $e->getMessage(),
                ));

                throw new ActionError(sprintf(
                    'could not reach %s to check that room — try again in a moment.',
                    $this->connector->label(),
                ));
            },
        );
    }

    private function here(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->link($context, Arguments::fromParts(
            [(string) ($arguments->named('target') ?? $arguments->get(0) ?? '')],
            ['target' => (string) ($arguments->named('target') ?? $arguments->get(0) ?? '')],
        ));
    }

    private function unlink(Context $context, Arguments $arguments): string
    {
        $guildId = $context->requireGuild();
        $channelId = $this->channel($context, $arguments, 0);
        $store = $context->bot->getStore();

        // This server's own bridges only. Reading across every server would
        // report another server's channel as unlinked while the store — which
        // is scoped by server — quietly left it alone.
        $before = $store->links($this->connector->name())->forGuild($guildId)[$channelId] ?? null;

        if ($before === null) {
            return sprintf('<#%s> was not bridged with %s.', $channelId, $this->connector->label());
        }

        $store->unlink($this->connector->name(), $guildId, $channelId);
        $context->bot->sync($this->connector->name());

        return sprintf('<#%s> is no longer bridged with **%s**.', $channelId, $before);
    }

    private function list(Context $context): string
    {
        $guildId = $context->requireGuild();
        $store = $context->bot->getStore();
        $items = [];

        foreach ($store->links($this->connector->name())->forGuild($guildId) as $channelId => $target) {
            $label = $store->label($this->connector->name(), $target);

            $items[] = sprintf(
                '<#%s> ⇄ **%s**%s',
                $channelId,
                $label ?? $target,
                $label === null ? '' : sprintf(' `%s`', $target),
            );
        }

        $listing = Format::listing(
            $context->surface,
            $items,
            sprintf('%s bridges in this server', $this->connector->label()),
            sprintf('none yet — `link` sets one up.'),
        );

        // What the startup check made of these. A bridge whose channel or room
        // went away while the bot was down reads exactly like a working one
        // from a listing alone.
        $check = $context->bot->getLastCheck();

        return $check === null || $items === [] || ! $context->surface->markdown
            ? $listing
            : $listing . "\n-# Last bridge check: " . $check;
    }

    private function status(Context $context): string
    {
        // Every adapter has already worked out which room this is about: on
        // Discord the one bridged to the channel, in a chat the chat itself.
        // Looking it up again from a Discord channel id would find nothing when
        // this is asked from the far end.
        $target = $context->target;

        if ($target === null) {
            return sprintf(
                'this channel is not bridged with %s. `link` sets one up.',
                $this->connector->label(),
            );
        }

        $label = $context->bot->getStore()->label($this->connector->name(), $target) ?? $target;
        $joined = in_array($target, $this->connector->joined(), true);

        return Format::fields($context->surface, [
            'bridged with' => $label,
            'id' => $target,
            'connected' => $joined,
            'queued' => $this->connector->queued(),
            'last check' => $context->bot->getLastCheck(),
        ], sprintf('%s bridge', $this->connector->label()));
    }

    /**
     * Asks first. Clearing every bridge in a server is irreversible, and it is
     * exactly the kind of thing somebody fires while meaning `list` — so this
     * answers with a confirmation panel, and the clearing happens in
     * {@see self::confirmed()} when its button is pressed.
     */
    private function reset(Context $context): string|PanelBuilder
    {
        $guildId = $context->requireGuild();
        $count = count($context->bot->getStore()->links($this->connector->name())->forGuild($guildId));

        if ($count === 0) {
            return 'there was nothing to clear.';
        }

        return PanelBuilder::confirm(
            sprintf(
                "**Clear all %d %s bridge%s in this server?**\nThis cannot be undone, and nothing will relay until they are linked again.",
                $count,
                $this->connector->label(),
                $count === 1 ? '' : 's',
            ),
            ComponentRouter::id(self::CONFIRM_RESET, $this->connector->name()),
            'Clear them all',
        );
    }

    /**
     * The `reset` button, pressed.
     *
     * The rung is checked again here rather than trusted from when the panel
     * was drawn. A prefix command's reply is visible to the whole channel, so
     * the person pressing the button need not be the person who asked.
     */
    public static function confirmed(Bot $bot, Interaction $interaction, string $connectorName): PromiseInterface
    {
        $guildId = (string) ($interaction->guild_id ?? '');
        $connector = $bot->connector($connectorName);
        $access = Permissions::accessForInteraction($interaction, $bot->getConfig()->discordOwnerId);

        if ($guildId === '' || $connector === null || ! $access->satisfies(Access::Administrator)) {
            return $interaction->respondWithMessage(
                PanelBuilder::error(sprintf('That is limited to %s.', Access::Administrator->label())),
                true,
            );
        }

        $count = count($bot->getStore()->links($connectorName)->forGuild($guildId));

        $bot->getStore()->forgetGuild($connectorName, $guildId);
        $bot->sync($connectorName);

        return $interaction->updateMessage(PanelBuilder::success(sprintf(
            'Cleared %d %s bridge%s in this server.',
            $count,
            $connector->label(),
            $count === 1 ? '' : 's',
        )));
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * Which Discord channel an argument names, defaulting to the one the
     * command was typed in.
     *
     * Accepts `<#id>` because that is what a Discord channel picker renders to
     * and what someone types by hand; both surfaces therefore agree.
     */
    private function channel(Context $context, Arguments $arguments, int $position): string
    {
        $raw = $arguments->named('channel') ?? $arguments->get($position);

        if ($raw !== null && preg_match('/^<#(\d+)>$/', trim($raw), $matches) === 1) {
            return $matches[1];
        }

        if ($raw !== null && preg_match('/^\d{5,}$/', trim($raw)) === 1) {
            return trim($raw);
        }

        return $context->channelId()
            ?? throw new ActionError('I could not work out which channel you meant.');
    }

    /**
     * Refuses a channel from another server.
     *
     * The permission gate checks that the invoker administers *this* server.
     * A channel mention is just an id, and the bot sits in many servers, so
     * without this an admin of one could name a channel in another — where
     * they may have no rights at all — and have it relayed into a public chat.
     *
     * @throws ActionError
     */
    private function requireChannelInGuild(Context $context, string $channelId, string $guildId): void
    {
        $channel = $context->bot->getChannel($channelId);

        if ($channel !== null && (string) ($channel->guild_id ?? '') === $guildId) {
            return;
        }

        throw new ActionError(sprintf('<#%s> is not a channel in this server.', $channelId));
    }

    /** Whatever was typed, as the connector addresses rooms. */
    private function target(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            throw new ActionError(sprintf('name a %s room to bridge with.', $this->connector->label()));
        }

        return $this->connector->normalise($raw)
            ?? throw new ActionError(sprintf(
                '`%s` does not look like a %s room.',
                mb_substr(trim($raw), 0, 60),
                $this->connector->label(),
            ));
    }

    /**
     * A warning when the bot cannot post where it has just been told to.
     *
     * A bridge that is configured correctly but cannot post looks exactly like
     * one that is misconfigured, and the only evidence is an absence — so it is
     * said up front rather than discovered when the first message vanishes.
     */
    private function permissionNote(Context $context, string $channelId): string
    {
        $channel = $context->bot->getChannel($channelId);

        if ($channel === null) {
            return "\n-# I can't see that channel yet, so I may not be able to post in it.";
        }

        $me = $channel->guild?->members?->get('id', (string) ($context->bot->id ?? '')) ?? null;
        $permissions = $me === null ? null : $channel->getBotPermissions();

        if ($permissions === null) {
            return '';
        }

        $missing = [];

        if (! $permissions->send_messages) {
            $missing[] = '**Send Messages**';
        }

        if (! $permissions->manage_webhooks) {
            $missing[] = '**Manage Webhooks**';
        }

        if ($missing === []) {
            return '';
        }

        return sprintf(
            "\n-# I am missing %s there. Without Send Messages nothing arrives at all; without Manage Webhooks it arrives as plain bot messages instead of per-person names and avatars.",
            implode(' and ', $missing),
        );
    }
}

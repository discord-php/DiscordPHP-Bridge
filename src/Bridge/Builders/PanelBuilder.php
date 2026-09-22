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

namespace Bridge\Builders;

use Bridge\Helpers\ComponentRouter;
use Bridge\Room;
use Bridge\Support\MessageText;
use Discord\Builders\Components\ActionRow;
use Discord\Builders\Components\Button;
use Discord\Builders\Components\ComponentObject;
use Discord\Builders\Components\Container;
use Discord\Builders\Components\Section;
use Discord\Builders\Components\Separator;
use Discord\Builders\Components\TextDisplay;
use Discord\Builders\MessageBuilder;

/**
 * A {@see MessageBuilder} that is a Components v2 panel: one accented
 * {@see Container} that everything else is added to.
 *
 * It extends `MessageBuilder` rather than wrapping it so a panel *is* a
 * message builder — anything that takes one (`respondWithMessage()`,
 * `updateMessage()`, `sendMessage()`, `Webhook::execute()`) takes a panel,
 * with no unwrapping step and nothing to keep in sync as DiscordPHP's builder
 * grows. The named constructors follow the same shape as
 * {@see Button::primary()}: a static per variant, then fluent methods.
 *
 * Components v2 rather than embeds because these panels are not decorated
 * text: a bridge list needs a button *per row* to unlink that row, which is
 * exactly what a {@see Section} accessory is for and which an embed cannot
 * express at all. Adding a v2 component sets the `IS_COMPONENTS_V2` flag,
 * which forbids `content` on the same message — so everything a panel says
 * lives in a {@see TextDisplay}.
 *
 * Every panel also sets `allowed_mentions: none`. Panels quote text supplied
 * by another network — a room title, somebody's name — and none of it should be
 * able to ping a Discord server.
 *
 * @since 1.0.0
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
class PanelBuilder extends MessageBuilder
{
    /** A calm blue, for ordinary panels. */
    public const ACCENT = 0x2AABEE;

    public const SUCCESS = 0x57F287;

    public const DANGER = 0xED4245;

    public const WARNING = 0xFEE75C;

    /**
     * Discord allows 40 components in one v2 message, and each row costs a
     * Section plus its text and its button. Ten rows leaves ample room for the
     * heading, the separator and the footer.
     */
    public const MAX_ROWS = 10;

    /** The container every other component is added to. */
    protected Container $container;

    /**
     * Creates an empty panel.
     *
     * @param int $accent Container accent colour; one of the constants above.
     */
    public static function new(int $accent = self::ACCENT): static
    {
        $panel = new static();
        $panel->container = Container::new()->setAccentColor($accent);

        $panel->setAllowedMentions(['parse' => []]);
        $panel->addComponent($panel->container);

        return $panel;
    }

    /** A panel that is one block of markdown. */
    public static function notice(string $markdown, int $accent = self::ACCENT): static
    {
        return static::new($accent)->addText($markdown);
    }

    public static function success(string $markdown): static
    {
        return static::notice($markdown, self::SUCCESS);
    }

    public static function error(string $markdown): static
    {
        return static::notice($markdown, self::DANGER);
    }

    public static function warning(string $markdown): static
    {
        return static::notice($markdown, self::WARNING);
    }

    /**
     * This server's bridges, one row per link, each with its own Unlink
     * button — the reason these panels are v2 and not embeds.
     *
     * @param list<array{channel_id: string, target: string, title: ?string}> $rows
     * @param string $connector Named in the empty state, so the instruction it
     *                          gives is one the reader can actually type.
     */
    public static function links(array $rows, string $connector = 'bridge'): static
    {
        if ($rows === []) {
            return static::notice(sprintf(
                "**No channels are bridged yet.**\n"
                . 'Use `/%1$s link` to start one, or `/%1$s here` in the channel you want bridged.',
                $connector,
            ));
        }

        $panel = static::new()
            ->addText('## Bridged channels')
            ->addSeparator();

        foreach (array_slice($rows, 0, self::MAX_ROWS) as $row) {
            $panel->addRow(
                sprintf(
                    "<#%s> ⇄ **%s**\n-# `%s`",
                    $row['channel_id'],
                    MessageText::escapeMarkdown($row['title'] ?? $row['target']),
                    $row['target'],
                ),
                Button::danger(ComponentRouter::id('unlink', $row['channel_id']))->setLabel('Unlink'),
            );
        }

        $hidden = count($rows) - self::MAX_ROWS;
        if ($hidden > 0) {
            $panel->addText(sprintf('-# …and %d more.', $hidden));
        }

        return $panel;
    }

    /**
     * The panel for one room on another network: what the bot can see about
     * it, and the things it can do to it from here.
     *
     * There is deliberately no thumbnail. On at least one network a room's
     * photo is reachable only through a URL containing the bot token, and a
     * panel is not the place to find that out — so the builder does not offer
     * the option at all.
     *
     * @param list<string> $linkedChannels Discord channels bridged to it, for the footer.
     */
    public static function room(Room $room, array $linkedChannels = []): static
    {
        $lines = [
            '## ' . MessageText::escapeMarkdown($room->label),
            sprintf('-# %s · `%s`', MessageText::escapeMarkdown($room->kind ?? 'room'), $room->id),
        ];

        if ($room->url !== null && $room->url !== '') {
            $lines[] = '🔗 ' . $room->url;
        }

        if ($room->members !== null) {
            $lines[] = sprintf('👥 **%s** member%s', number_format($room->members), $room->members === 1 ? '' : 's');
        }

        if ($room->description !== null && $room->description !== '') {
            $lines[] = '';
            $lines[] = '> ' . str_replace("\n", "\n> ", MessageText::escapeMarkdown(MessageText::truncate($room->description, 400)));
        }

        $lines[] = '';
        $lines[] = $linkedChannels === []
            ? '-# Not bridged to any channel here.'
            : '-# Bridged to ' . implode(', ', array_map(static fn (string $id): string => '<#' . $id . '>', $linkedChannels));

        return static::new()
            ->addText(implode("\n", $lines))
            ->addSeparator()
            ->addActions(
                Button::secondary(ComponentRouter::id('room', $room->id))->setLabel('Refresh')->setEmoji('🔄'),
                Button::secondary(ComponentRouter::id('invite', $room->id))->setLabel('Invite link')->setEmoji('🔗'),
                Button::secondary(ComponentRouter::id('members', $room->id))->setLabel('Member count')->setEmoji('👥'),
            );
    }

    /**
     * A destructive action behind a second press.
     *
     * `reset` drops every bridge in the server, which is exactly the kind of
     * thing somebody fires while meaning `list`.
     */
    public static function confirm(string $markdown, string $confirmCustomId, string $confirmLabel = 'Yes, do it'): static
    {
        return static::new(self::DANGER)
            ->addText($markdown)
            ->addActions(
                Button::danger($confirmCustomId)->setLabel($confirmLabel),
                Button::secondary(ComponentRouter::id('dismiss'))->setLabel('Cancel'),
            );
    }

    /** Adds a block of markdown. */
    public function addText(string $markdown): static
    {
        $this->container->addComponent(TextDisplay::new($markdown));

        return $this;
    }

    /** Adds a divider. */
    public function addSeparator(bool $divider = true): static
    {
        $this->container->addComponent(Separator::new()->setDivider($divider));

        return $this;
    }

    /** Adds a line of text with a component — usually a button — beside it. */
    public function addRow(string $markdown, ComponentObject $accessory): static
    {
        $this->container->addComponent(
            Section::new()
                ->addComponent(TextDisplay::new($markdown))
                ->setAccessory($accessory),
        );

        return $this;
    }

    /** Adds a row of buttons. */
    public function addActions(Button ...$buttons): static
    {
        $row = ActionRow::new();

        foreach ($buttons as $button) {
            $row->addComponent($button);
        }

        $this->container->addComponent($row);

        return $this;
    }

    /** Recolours the container after the fact. */
    public function setAccentColor(int $accent): static
    {
        $this->container->setAccentColor($accent);

        return $this;
    }

    /** The container, for anything these helpers do not cover. */
    public function getContainer(): Container
    {
        return $this->container;
    }
}

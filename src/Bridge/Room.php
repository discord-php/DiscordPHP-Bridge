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

namespace Bridge;

/**
 * A place on another network that a Discord channel can be bridged to: a
 * Twitch channel, a Telegram group, whatever a later connector brings.
 *
 * What every platform has in common is less than it looks — an id and
 * something to call it — so everything beyond that is optional and rendered
 * only when the connector supplies it. A connector that cannot cheaply answer
 * "how many people are in here" says `null` rather than guessing.
 *
 * Returned by {@see Connector::resolve()}, which is what `link` uses to check a
 * target exists before wiring it up. A typo otherwise produces a bridge that
 * silently never works, because the bot joins a room that isn't there and
 * never hears anything.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Room
{
    /**
     * @param string  $id          As the connector addresses it, and as {@see Store} keeps it.
     * @param string  $label       What to call it in front of a human.
     * @param ?string $url         Somewhere to open it, when the platform has public links.
     * @param ?string $kind        The platform's own word for what this is — "channel", "supergroup".
     * @param ?int    $members     Only when the platform makes it cheap to ask.
     * @param ?string $description As the room describes itself.
     * @param ?string $avatarUrl   For a panel's thumbnail.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly ?string $url = null,
        public readonly ?string $kind = null,
        public readonly ?int $members = null,
        public readonly ?string $description = null,
        public readonly ?string $avatarUrl = null,
    ) {
    }
}

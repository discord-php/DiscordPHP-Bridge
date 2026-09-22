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

namespace Bridge\Command;

use Bridge\Support\MessageText;

/**
 * Which chat an action was invoked from, and what that chat can take.
 *
 * Actions are written once and registered into every chat the bot is in, so
 * the handful of places that genuinely must differ — how long an answer may
 * be, whether markdown renders, whether a name is worth linking — ask the
 * surface rather than being written twice.
 *
 * Deliberately a value object rather than an enum. An enum would mean the core
 * has to grow a case before anyone can write a connector for a fourth network,
 * which is exactly the coupling the connector model exists to avoid: a
 * connector declares its own surface and the core has never heard of it.
 *
 * Discord is the exception, and the one case the core does own — every
 * installation has it, and it is the surface slash commands and panels live on.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Surface
{
    /** The name of the one surface every installation has. */
    public const DISCORD = 'discord';

    /**
     * @param string $name     Matches the connector's {@see \Bridge\Connector::name()}.
     * @param string $label    What to call it in front of a human.
     * @param int    $limit    The longest message body the surface accepts.
     * @param bool   $markdown Whether it renders Discord-style markdown.
     * @param bool   $lines    Whether a message can contain a line break at all.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly int $limit,
        public readonly bool $markdown = false,
        public readonly bool $lines = true,
    ) {
    }

    public static function discord(): self
    {
        return new self(self::DISCORD, 'Discord', MessageText::DISCORD_LIMIT, markdown: true);
    }

    public function isDiscord(): bool
    {
        return $this->name === self::DISCORD;
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }
}

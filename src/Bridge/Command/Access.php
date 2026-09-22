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

namespace Bridge\Command;

/**
 * One permission ladder for permission systems that agree on nothing.
 *
 * Twitch has chat badges, Telegram has chat administrators and a creator,
 * Discord has a role bitfield and a guild owner. An action declares the rung it
 * needs, and each adapter is responsible for working out which rung the invoker
 * stands on — see {@see Context::$access}.
 *
 * The ladder is deliberately coarse, and it is the only one. Anything finer
 * would have to be explained once per platform, and an action's author would
 * have to know all of them; anything per-platform would mean a command's
 * permissions changed depending on which chat it was typed in, which is how
 * somebody ends up moderating a Twitch channel from a Telegram group they
 * happen to be an admin of.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
enum Access: int
{
    /** Anyone in chat. */
    case Everyone = 0;

    /**
     * Whoever the room trusts to police it: a Twitch moderator, a Telegram
     * chat administrator, Discord's Manage Messages or above.
     */
    case Moderator = 1;

    /**
     * The person whose room it is — the Twitch broadcaster, a Telegram chat's
     * creator, a Discord guild's owner or an Administrator.
     *
     * This is the rung that may change what the room itself looks like, and the
     * one that decides which Discord channel gets copied into a public chat.
     */
    case Administrator = 2;

    /**
     * Whoever runs the bot process. Not a chat role at all: it is configured in
     * the environment, and it gates the things that can reach any endpoint or
     * read back a secret.
     */
    case Operator = 3;

    /** Whether standing on this rung is enough to run something needing `$required`. */
    public function satisfies(self $required): bool
    {
        return $this->value >= $required->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'everyone',
            self::Moderator => 'moderators',
            self::Administrator => 'whoever the room belongs to',
            self::Operator => 'the bot owner',
        };
    }
}

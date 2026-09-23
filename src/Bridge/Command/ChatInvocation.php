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

/**
 * Somebody typing a command in a connector's own chat, as far as the
 * {@see ChatDispatcher} needs to know.
 *
 * The connector fills this in from its own message type — it is the only code
 * that knows where a Twitch badge or a Telegram admin list lives — and the
 * dispatcher does everything else identically for every network.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class ChatInvocation
{
    /**
     * @param string  $room        The room it was typed in, as {@see \Bridge\Links} keys it.
     * @param ?string $roomId      The network's own id for that room, when it differs.
     * @param string  $invokerName A display name, for addressing a reply.
     * @param string  $invokerId   The network's id for the person — what cooldowns key on.
     * @param Access  $access      Their rung on the one ladder, read off the network's idea of rank.
     * @param ?object $message     The originating message, for an action that needs it.
     */
    public function __construct(
        public readonly string $room,
        public readonly ?string $roomId,
        public readonly string $invokerName,
        public readonly string $invokerId,
        public readonly Access $access,
        public readonly ?object $message = null,
    ) {
    }
}

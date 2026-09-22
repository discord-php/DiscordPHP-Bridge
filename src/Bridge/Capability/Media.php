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

namespace Bridge\Capability;

use Bridge\Message\Media as Attachment;
use Bridge\Message\Outgoing;
use React\Promise\PromiseInterface;

/**
 * A connector whose network can carry a picture rather than a link to one.
 *
 * The distinction is not cosmetic. Twitch chat is text, so an attachment can
 * only ever be relayed as a URL — and Discord's CDN links expire in about a
 * day, so that link is dead by the time anyone reads the logs. Telegram can
 * take the picture itself, and should, because then it is actually there.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface Media
{
    /**
     * Sends a picture or file into a room.
     *
     * The whole {@see Outgoing} comes with it rather than a finished caption,
     * because composing one is the connector's business: the caption limit is
     * not the message limit on any network that has both, and the escaping is
     * the connector's own.
     *
     * Rejects when the network will not take it — too large, wrong type, a link
     * it cannot reach — and the relay falls back to sending it as a link in the
     * text, which every network can carry.
     *
     * @return PromiseInterface<?string> The id of what was sent, when the network has them.
     */
    public function sendMedia(string $target, Attachment $media, ?Outgoing $message = null): PromiseInterface;
}

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
 * A connector whose network can carry a picture rather than a link to one, in
 * both directions.
 *
 * The distinction is not cosmetic. Twitch chat is text, so an attachment can
 * only ever be relayed as a URL — and Discord's CDN links expire in about a
 * day, so that link is dead by the time anyone reads the logs. Telegram can
 * take the picture itself, and should, because then it is actually there.
 *
 * The other direction matters as much. Some networks never hand out a URL a
 * stranger may follow — a Telegram file link has the bot token in its path —
 * so the only way a picture from there reaches Discord at all is for the
 * connector to fetch the bytes and the relay to upload them.
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

    /**
     * Fetches the bytes of something that arrived from this network, so the
     * relay can upload it into Discord.
     *
     * Resolves to `null` for anything the connector will not copy — too large
     * for Discord to accept, or not a file at all — and the relay names it
     * instead. Never resolves to, rejects with, or logs a URL: on at least one
     * network that URL is a credential.
     *
     * @return PromiseInterface<array{filename: string, content: string}|null>
     */
    public function fetchMedia(Attachment $media): PromiseInterface;
}

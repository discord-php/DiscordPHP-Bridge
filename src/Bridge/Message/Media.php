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

namespace Bridge\Message;

/**
 * A picture or a file attached to a message.
 *
 * `url` is deliberately nullable, and the difference matters. A Discord
 * attachment arrives with a CDN link anyone can open; a Telegram photo arrives
 * as a file *id*, and turning that into a URL means asking the Bot API — which
 * returns a link **with the bot token in the path**. So a connector that can
 * only offer such a link hands over `null` and keeps the token to itself, and
 * the relay falls back to uploading the bytes or naming the file.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Media
{
    /** Renderable by the destination as an image rather than a link. */
    public const IMAGE = 'image';

    /** Anything else: a document, an audio clip, a video. */
    public const FILE = 'file';

    /**
     * @param string  $kind    One of {@see IMAGE} or {@see FILE}.
     * @param ?string $url     A link anyone may follow — never one carrying a credential.
     * @param ?string $id      The platform's own handle for it, for a connector that must re-fetch.
     * @param ?string $name    A filename to show.
     * @param ?string $caption Text the platform attached to the file rather than to the message.
     * @param ?int    $size    In bytes, when the platform says — what decides whether it is
     *                         small enough to copy across rather than describe.
     * @param ?string $link    A public page showing it — a post on t.me — for when there is
     *                         no `url` to the file itself. What a network that can only
     *                         carry text is given instead of the file's name.
     * @param ?string $mimeType As the platform reported it, e.g. `image/gif` — how a
     *                          network that sends a GIF differently from a still
     *                          photo tells the two apart.
     */
    public function __construct(
        public readonly string $kind = self::FILE,
        public readonly ?string $url = null,
        public readonly ?string $id = null,
        public readonly ?string $name = null,
        public readonly ?string $caption = null,
        public readonly ?int $size = null,
        public readonly ?string $link = null,
        public readonly ?string $mimeType = null,
    ) {
    }

    public function isImage(): bool
    {
        return $this->kind === self::IMAGE;
    }
}

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

namespace Bridge\Support;

use Bridge\Message\Media;

/**
 * Recognises a link to a picture on Discord's own CDN.
 *
 * Discord's GIF picker sends a favourited image as a bare link, and Discord
 * shows that link as the picture itself. Relayed as text it is only a link
 * everywhere else, so a message that is nothing but one of these is relayed as
 * the picture instead.
 *
 * Only Discord's CDN, because its URLs say what a file is without fetching it:
 * the extension is the format. Its image endpoints — avatars, banners, icons,
 * emojis — also render an animated image in whichever format is asked for, and
 * an animated WebP is asked for as a GIF, the one animated format every network
 * plays. Uploaded files are served as they are, so those are taken as given.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class DiscordMedia
{
    private const HOSTS = ['cdn.discordapp.com', 'media.discordapp.net'];

    /** The image formats Discord serves, by extension. */
    private const TYPES = [
        'gif' => 'image/gif',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /** A picture on Discord's CDN, or `null` for anything else. */
    public static function fromUrl(string $url): ?Media
    {
        if (! MessageText::isRelayableUrl($url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, self::HOSTS, true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');

        if (preg_match('/\.([A-Za-z]+)$/', $path, $match) !== 1 || ! isset(self::TYPES[strtolower($match[1])])) {
            return null;
        }

        $extension = strtolower($match[1]);
        parse_str((string) ($parts['query'] ?? ''), $query);

        $uploaded = str_starts_with($path, '/attachments/') || str_starts_with($path, '/ephemeral-attachments/');

        if (! $uploaded && $extension === 'webp' && ($query['animated'] ?? '') === 'true') {
            unset($query['animated']);
            $path = substr($path, 0, -\strlen($match[1])) . 'gif';
            $extension = 'gif';
            $url = 'https://' . $host . $path . ($query === [] ? '' : '?' . http_build_query($query));
        }

        return new Media(Media::IMAGE, $url, name: basename($path), mimeType: self::TYPES[$extension]);
    }
}

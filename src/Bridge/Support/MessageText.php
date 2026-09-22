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

namespace Bridge\Support;

/**
 * Turning a message from one network into text the other will accept.
 *
 * What lives here is the half that has nothing to do with any particular
 * platform: stripping the control characters no chat should carry, rendering
 * Discord's mention markup as something legible, fitting attachment links into
 * a budget, and truncating without cutting a character in half.
 *
 * What does *not* live here is anything a connector's own network requires —
 * IRC line sanitisation, Telegram's HTML escaping, the format of a channel
 * reference. Those belong to the connector, which is the only code that should
 * need to know them, and they are built out of the primitives below.
 *
 * Every method is static and every one is pure: the routing, the pacing and
 * the delivery all have sockets in them, and this deliberately does not.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class MessageText
{
    /** Discord's own message limit. */
    public const DISCORD_LIMIT = 2000;

    /**
     * Strips the control characters no network should ever carry, while
     * keeping the ones that carry meaning in a chat message.
     *
     * Newline and tab survive; everything else in C0, plus DEL, goes. A bare
     * carriage return is normalised rather than dropped so a message pasted
     * from Windows does not arrive with its lines run together.
     */
    public static function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    }

    /**
     * Every run of whitespace reduced to one space, and the ends trimmed.
     *
     * For sources that are line-oriented by nature — IRC cannot express a
     * newline inside a message, so a run of them means nothing was there.
     */
    public static function collapseWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Formats a message from another network for Discord, or `null` when there
     * is nothing worth relaying.
     *
     * Content is passed through as written. Nothing is escaped, because the
     * delivery side sends `allowed_mentions: none` — that neuters `@everyone`
     * at the API rather than by mangling the text, so someone who types an `@`
     * still reads as having typed one. The sending network's own markup is left
     * alone for the same reason: mangling asterisks to stop Discord bolding
     * them is a worse result than the occasional stray bold.
     *
     * @param bool $flatten Whether the source is line-oriented, so runs of
     *                      whitespace carry no meaning and should collapse.
     */
    public static function forDiscord(string $content, int $limit = self::DISCORD_LIMIT, bool $flatten = false): ?string
    {
        $body = self::sanitize($content);
        $body = $flatten ? self::collapseWhitespace($body) : trim($body);

        return $body === '' ? null : self::truncate($body, $limit);
    }

    /**
     * Rewrites Discord's `<@id>` / `<#id>` / `<@&id>` / `<a:name:id>` markup
     * into something legible in a plain-text chat. Unknown ids degrade to a
     * readable placeholder rather than leaking a raw snowflake.
     *
     * @param array<string, string> $userNames
     * @param array<string, string> $channelNames
     * @param array<string, string> $roleNames
     */
    public static function resolveMentions(
        string $content,
        array $userNames = [],
        array $channelNames = [],
        array $roleNames = [],
    ): string {
        // Custom emoji <:name:id> / <a:name:id> → :name:
        $content = preg_replace('/<a?:([A-Za-z0-9_]+):\d+>/', ':$1:', $content) ?? $content;

        $content = preg_replace_callback(
            '/<@!?(\d+)>/',
            static fn (array $m): string => '@' . ($userNames[$m[1]] ?? 'someone'),
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            '/<@&(\d+)>/',
            static fn (array $m): string => '@' . ($roleNames[$m[1]] ?? 'role'),
            $content,
        ) ?? $content;

        return preg_replace_callback(
            '/<#(\d+)>/',
            static fn (array $m): string => '#' . ($channelNames[$m[1]] ?? 'channel'),
            $content,
        ) ?? $content;
    }

    /**
     * Renders attachment URLs into the space left over, whole links only.
     *
     * Fits as many as the budget allows and counts the rest, since a truncated
     * URL is worse than an honest "(+2 more)" — it looks clickable and goes
     * nowhere. When not even one fits, it degrades to a bare count, which is at
     * least a signal that something was posted.
     *
     * A caveat worth knowing rather than discovering: Discord's CDN links are
     * signed and expire roughly a day after they are issued. A relayed link
     * works for people reading along live, and will be dead by the time anyone
     * reads the logs. Nothing here can prevent that — the unsigned form of
     * these URLs no longer exists.
     *
     * @param list<string>                 $urls
     * @param (callable(string): string)|null $sanitise How the destination network
     *                                                 needs each URL cleaned; defaults
     *                                                 to {@see sanitize()}.
     */
    public static function attachmentLinks(array $urls, int $budget, ?callable $sanitise = null): string
    {
        $sanitise ??= self::sanitize(...);

        $urls = array_values(array_filter(array_map(
            static fn (mixed $url): string => $sanitise((string) $url),
            $urls,
        ), self::isRelayableUrl(...)));

        if ($urls === []) {
            return '';
        }

        $rendered = '';
        $shown = 0;

        foreach ($urls as $url) {
            $remaining = count($urls) - ($shown + 1);
            $tail = $remaining > 0 ? sprintf(' (+%d more)', $remaining) : '';
            $candidate = $rendered . ' ' . $url;

            if (self::length($candidate . $tail) > $budget) {
                break;
            }

            $rendered = $candidate;
            ++$shown;
        }

        if ($shown === 0) {
            $marker = sprintf(' [%d file%s]', count($urls), count($urls) === 1 ? '' : 's');

            return self::length($marker) <= $budget ? $marker : '';
        }

        $remaining = count($urls) - $shown;

        return $rendered . ($remaining > 0 ? sprintf(' (+%d more)', $remaining) : '');
    }

    /**
     * Whether a URL is safe to put in front of a public chat as a link.
     *
     * The host is deliberately not pinned to Discord's CDN. These come from the
     * gateway's own attachment objects, so they are already trusted, and
     * hard-coding hostnames would mean a future CDN domain silently degrading
     * every attachment to a bare count. What is checked is the shape: HTTPS
     * only, and nothing that could break out of a single line.
     */
    public static function isRelayableUrl(string $url): bool
    {
        return $url !== ''
            && str_starts_with($url, 'https://')
            && preg_match('/[\s\x00-\x1F\x7F]/u', $url) !== 1;
    }

    /**
     * Escapes Discord markdown in text that came from elsewhere.
     *
     * For structured output — a chat title in a panel, a name in a heading —
     * where an underscore should read as an underscore rather than silently
     * italicising the rest of the line. Relayed *messages* are deliberately
     * not escaped this way; see {@see forDiscord()}.
     */
    public static function escapeMarkdown(string $text): string
    {
        return preg_replace('/([*_~`|\\\\>#-])/', '\\\\$1', $text) ?? $text;
    }

    /** Truncates on a character boundary, marking that it happened. */
    public static function truncate(string $text, int $limit): string
    {
        if ($limit <= 0 || self::length($text) <= $limit) {
            return $text;
        }

        $ellipsis = '…';
        $keep = max(0, $limit - 1);

        return (function_exists('mb_substr') ? mb_substr($text, 0, $keep, 'UTF-8') : substr($text, 0, $keep)) . $ellipsis;
    }

    /** The last path segment of a URL, for labelling an attachment link. */
    public static function filename(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $name = rawurldecode(basename($path));

        return $name === '' ? 'attachment' : self::truncate($name, 60);
    }

    /** Characters, not bytes: every limit these networks publish is a character count. */
    public static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}

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

/**
 * Reads the application's install settings back and says what is wrong with
 * them, once, at startup.
 *
 * Who can add the bot to a server is decided in the Developer Portal, not in
 * this process — and it decides a great deal. Whoever can link a room in *any*
 * server the bot is in can reach every Twitch channel and Telegram chat the
 * bot can, so a bot anyone can add is a bot anyone can point at those rooms.
 * The portal says nothing when it is left that way, so this does.
 *
 * Pure: it takes the application as Discord returned it and hands back
 * findings, so it can be tested without a connection.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class InstallCheck
{
    public const WARNING = 'warning';

    public const INFO = 'info';

    /** Discord's installation-context key for a user install. */
    private const USER_INSTALL = '1';

    /**
     * @param object $application Discord's application object: a DiscordPHP
     *                            `Application` part, or the raw payload.
     *
     * @return list<array{level: string, message: string}>
     */
    public static function review(object $application): array
    {
        $findings = [];
        $public = (bool) ($application->bot_public ?? false);
        $installUrl = (string) ($application->custom_install_url ?? '');

        if ($public) {
            $findings[] = self::warning(
                'Public Bot is on, so anyone can add this bot to a server — and any admin of that server can then '
                . 'bridge any room the bot can reach. Turn it off under Bot in the Developer Portal.',
            );
        }

        if ((bool) ($application->bot_require_code_grant ?? false)) {
            $findings[] = self::warning(
                'Requires OAuth2 Code Grant is on, so the bot only joins a server once something exchanges the '
                . 'install code — and nothing in this bot does. Turn it off under Bot in the Developer Portal.',
            );
        }

        if (self::allowsUserInstall($application->integration_types_config ?? null)) {
            $findings[] = self::info(
                'User Install is enabled, but every command is registered for server installs only, so there is '
                . 'nothing to use it for. Untick it under Installation in the Developer Portal.',
            );
        }

        if ($installUrl !== '') {
            $findings[] = self::info('install link: ' . $installUrl);

            // An install page that sends people back to itself — the one on
            // valgorithms.com does — only works once that address is a
            // registered redirect, and Discord's error does not say so.
            $page = self::withoutQuery($installUrl);
            $redirects = array_map(strval(...), (array) ($application->redirect_uris ?? []));

            if ($page !== null && ! in_array($page, $redirects, true)) {
                $findings[] = self::warning(sprintf(
                    'the install link\'s page, %s, is not a registered redirect. If it sends people back to itself, '
                    . 'Discord rejects the install with "Invalid OAuth2 redirect_uri" — add it under OAuth2 → Redirects.',
                    $page,
                ));
            }
        } elseif (! $public) {
            $findings[] = self::info(
                'no install link is set; only you can add the bot, from an authorize URL you build yourself.',
            );
        }

        return $findings;
    }

    private static function allowsUserInstall(mixed $config): bool
    {
        if (is_object($config)) {
            $config = (array) $config;
        }

        return is_array($config) && array_key_exists(self::USER_INSTALL, $config);
    }

    /** `https://host/path`, or `null` when it is not an https URL. */
    private static function withoutQuery(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            return null;
        }

        return 'https://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/');
    }

    /** @return array{level: string, message: string} */
    private static function warning(string $message): array
    {
        return ['level' => self::WARNING, 'message' => $message];
    }

    /** @return array{level: string, message: string} */
    private static function info(string $message): array
    {
        return ['level' => self::INFO, 'message' => $message];
    }
}

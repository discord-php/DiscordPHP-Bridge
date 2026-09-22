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
 * What the bot itself needs to start, as opposed to what any one connector
 * needs.
 *
 * Deliberately short. A connector reads its own settings out of the same
 * {@see Environment}, so adding a network does not mean adding fields here —
 * which is what would otherwise make the core grow a little every time a
 * package it has never heard of is installed.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Config
{
    /** What the bot answers to on Discord when no prefix is configured. */
    public const DEFAULT_PREFIX = '!';

    /** The name the relay's webhooks are created under. */
    public const DEFAULT_WEBHOOK_NAME = 'Bridge';

    private function __construct(
        public readonly string $discordToken,
        public readonly string $discordPrefix,
        public readonly ?string $discordOwnerId,
        public readonly string $storePath,
        public readonly string $logLevel,
        public readonly string $webhookName,
        public readonly Environment $environment,
    ) {
    }

    /**
     * @throws \RuntimeException when a required value is missing.
     */
    public static function fromEnvironment(Environment $environment, string $storePath): self
    {
        return new self(
            discordToken: $environment->require('DISCORD_TOKEN'),
            discordPrefix: $environment->or('DISCORD_PREFIX', self::DEFAULT_PREFIX),
            discordOwnerId: $environment->get('DISCORD_OWNER_ID'),
            storePath: $storePath,
            logLevel: strtolower($environment->or('LOG_LEVEL', 'info')),
            webhookName: $environment->or('WEBHOOK_NAME', self::DEFAULT_WEBHOOK_NAME),
            environment: $environment,
        );
    }

    /**
     * Whether anyone at all can run operator-gated commands, and whether there
     * is anybody to tell when a bridge breaks.
     *
     * Optional, and unset is the intended default: a bot that can reach any
     * endpoint should require someone to have said who is allowed to do that.
     */
    public function hasOwner(): bool
    {
        return ($this->discordOwnerId ?? '') !== '';
    }
}

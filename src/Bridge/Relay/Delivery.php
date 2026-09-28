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

namespace Bridge\Relay;

use Discord\Parts\Channel\Channel;
use React\Promise\PromiseInterface;

/**
 * How another network's chat gets into Discord, and out again.
 *
 * {@see WebhookDelivery} is the one the bot uses. The relay depends on this
 * rather than on it so that what the relay decides to post, edit and delete
 * can be tested without a Discord to post to.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface Delivery
{
    /**
     * Posts one message into one channel, and says where it landed.
     *
     * @param array{filename: string, content: string}|null $file
     *
     * @return PromiseInterface<array{message_id: string, via: string}>
     */
    public function deliver(
        Channel $channel,
        string $author,
        ?string $text,
        ?string $avatarUrl = null,
        string $suffix = '',
        ?array $file = null,
    ): PromiseInterface;

    /**
     * Rewrites a copy this delivery posted.
     *
     * @return PromiseInterface<mixed>
     */
    public function edit(
        Channel $channel,
        string $messageId,
        string $via,
        string $author,
        string $text,
        string $suffix = '',
    ): PromiseInterface;

    /**
     * Removes a copy this delivery posted.
     *
     * @return PromiseInterface<null>
     */
    public function delete(Channel $channel, string $messageId, string $via): PromiseInterface;

    /** Forgets what it knows about a channel, after delivery there failed. */
    public function forget(Channel $channel): void;
}

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

namespace Bridge\Tests\Doubles;

use Bridge\Relay\Delivery;
use Bridge\Relay\WebhookDelivery;
use Discord\Parts\Channel\Channel;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * A {@see Delivery} with no Discord behind it: it writes down what it was
 * asked to post, edit and delete, and every post lands as `d1`, `d2`, ...
 */
final class RecordingDelivery implements Delivery
{
    /** @var list<array{channel: string, author: string, text: ?string}> */
    public array $delivered = [];

    /** @var list<array{channel: string, message_id: string, text: string}> */
    public array $edited = [];

    /** @var list<array{channel: string, message_id: string, via: string}> */
    public array $deleted = [];

    public function deliver(
        Channel $channel,
        string $author,
        ?string $text,
        ?string $avatarUrl = null,
        string $suffix = '',
        ?array $file = null,
    ): PromiseInterface {
        $this->delivered[] = ['channel' => (string) $channel->id, 'author' => $author . $suffix, 'text' => $text];

        return resolve(['message_id' => 'd' . count($this->delivered), 'via' => WebhookDelivery::VIA_WEBHOOK]);
    }

    public function edit(
        Channel $channel,
        string $messageId,
        string $via,
        string $author,
        string $text,
        string $suffix = '',
    ): PromiseInterface {
        $this->edited[] = ['channel' => (string) $channel->id, 'message_id' => $messageId, 'text' => $text];

        return resolve(null);
    }

    public function delete(Channel $channel, string $messageId, string $via): PromiseInterface
    {
        $this->deleted[] = ['channel' => (string) $channel->id, 'message_id' => $messageId, 'via' => $via];

        return resolve(null);
    }

    public function forget(Channel $channel): void
    {
    }
}

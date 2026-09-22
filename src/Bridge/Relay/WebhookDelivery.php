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

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Webhook;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Posts another network's chat into Discord.
 *
 * Uses a webhook so each relayed line carries the speaker's own name and
 * avatar instead of arriving as a wall of identical bot messages. That is not
 * only cosmetic: webhook messages carry a `webhook_id`, which is how the relay
 * recognises its own output and refuses to send it back out again.
 *
 * Webhooks need **Manage Webhooks**. When that is missing — or creation fails
 * for any other reason — delivery falls back to an ordinary bot message with
 * the author's name inline, so a misconfigured server degrades to an uglier
 * bridge rather than a silent one.
 *
 * ## Staying inside Discord's budget
 *
 * Two things here exist because of the rate limits rather than the feature:
 *
 * - **Every send goes through {@see OutboundPacer}.** One message on a busy
 *   network fans out to every Discord channel following that room, and with
 *   more than one connector installed they all draw on one token's budget.
 * - **A channel we cannot create a webhook in is remembered.** Without that
 *   memo, a server that never granted Manage Webhooks costs one rejected
 *   request *per relayed message* — and 10,000 rejections in ten minutes is a
 *   Cloudflare ban on the whole host, not a throttle.
 *
 * Nothing here builds an HTTP client. `Webhook::execute()` and
 * `Channel::sendMessage()` both go through the one `Discord\Http` the bot owns,
 * which is where the per-route buckets live.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class WebhookDelivery
{
    /** Discord rejects a webhook username longer than this. */
    public const USERNAME_LIMIT = 80;

    /** @var array<string, Webhook|false> Channel id => webhook, or false when we know we can't have one. */
    private array $cache = [];

    public function __construct(
        private readonly Discord $discord,
        private readonly OutboundPacer $pacer,
        private readonly string $webhookName = 'Bridge',
    ) {
    }

    /**
     * Delivers one message to one Discord channel.
     *
     * `allowed_mentions` is empty on every path: this text came from another
     * network and is untrusted, and somebody typing `@everyone` there must not
     * ping a Discord server. Neutering it at the API rather than by mangling
     * the text means they still read as having typed it.
     *
     * @param string $suffix Appended to the display name, so it is obvious a
     *                       relayed line is not a Discord account.
     */
    public function deliver(
        Channel $channel,
        string $author,
        string $text,
        ?string $avatarUrl = null,
        string $suffix = '',
    ): PromiseInterface {
        return $this->pacer->enqueue(
            (string) $channel->id,
            fn (): PromiseInterface => $this->webhookFor($channel)->then(
                function (Webhook $webhook) use ($author, $text, $avatarUrl, $suffix): PromiseInterface {
                    $payload = [
                        'content' => $text,
                        'username' => self::safeUsername($author, $suffix),
                        'allowed_mentions' => ['parse' => []],
                    ];

                    if ($avatarUrl !== null) {
                        $payload['avatar_url'] = $avatarUrl;
                    }

                    return $webhook->execute($payload);
                },
                fn () => $this->fallback($channel, $author, $text, $suffix),
            ),
        );
    }

    /** Forgets a channel's cached webhook — call when delivery starts failing. */
    public function forget(Channel $channel): void
    {
        unset($this->cache[(string) $channel->id]);
    }

    /** How many deliveries are waiting on Discord's budget. */
    public function queued(): int
    {
        return $this->pacer->queued();
    }

    /** @return PromiseInterface<Webhook> */
    private function webhookFor(Channel $channel): PromiseInterface
    {
        $id = (string) $channel->id;

        if (isset($this->cache[$id])) {
            return $this->cache[$id] === false
                ? reject(new \RuntimeException('no webhook available'))
                : resolve($this->cache[$id]);
        }

        return $channel->webhooks->freshen()->then(
            function ($webhooks) use ($channel, $id): PromiseInterface {
                foreach ($webhooks as $webhook) {
                    // Reuse only our own: another integration's webhook is not
                    // ours to post through.
                    if ($webhook->name === $this->webhookName
                        && (string) ($webhook->application_id ?? '') === (string) ($this->discord->application->id ?? '')) {
                        $this->cache[$id] = $webhook;

                        return resolve($webhook);
                    }
                }

                return $this->create($channel);
            },
            fn () => $this->create($channel),
        );
    }

    /** @return PromiseInterface<Webhook> */
    private function create(Channel $channel): PromiseInterface
    {
        $id = (string) $channel->id;

        return $channel->webhooks->save(
            $channel->webhooks->create(['name' => $this->webhookName]),
            'chat relay',
        )->then(
            function (Webhook $webhook) use ($id): Webhook {
                $this->cache[$id] = $webhook;

                return $webhook;
            },
            function (\Throwable $e) use ($id): never {
                // Remember the failure so every relayed line doesn't retry a
                // permission we demonstrably lack — see the class docblock.
                $this->cache[$id] = false;

                throw $e;
            },
        );
    }

    private function fallback(Channel $channel, string $author, string $text, string $suffix): PromiseInterface
    {
        return $channel->sendMessage(
            MessageBuilder::new()
                ->setContent(sprintf('**%s%s:** %s', self::escape($author), $suffix, $text))
                ->setAllowedMentions(['parse' => []]),
        );
    }

    /**
     * Discord rejects webhook usernames containing "discord", and caps them at
     * 80 characters. A display name from another network can be either, and the
     * bridge should not drop a message over it.
     */
    public static function safeUsername(string $author, string $suffix = ''): string
    {
        $name = trim(str_ireplace('discord', 'disc*rd', $author));
        $name = mb_substr(
            $name === '' ? 'someone' : $name,
            0,
            max(1, self::USERNAME_LIMIT - mb_strlen($suffix)),
            'UTF-8',
        );

        return $name . $suffix;
    }

    /** Neutralises Discord markdown in a name shown in the fallback path. */
    private static function escape(string $text): string
    {
        return preg_replace('/([*_~`|\\\\])/', '\\\\$1', $text) ?? $text;
    }
}

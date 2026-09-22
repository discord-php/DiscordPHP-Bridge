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

namespace Bridge\Relay;

use Bridge\Bot;
use Bridge\Capability\Editing;
use Bridge\Connector;
use Bridge\Message\Incoming;
use Bridge\Message\Media;
use Bridge\Message\Outgoing;
use Bridge\Support\MessageText;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;

/**
 * The relay itself: Discord chat out to every connector, and every connector's
 * chat back into Discord.
 *
 * The hard requirement here is that nothing the bot says can come back to it.
 * A bridge that repeats its own output is an infinite loop that gets the
 * account banned from both networks within minutes, so each direction drops its
 * own traffic as early as it can — see {@see shouldRelayFromDiscord()} and
 * {@see Incoming::$own}, which the connector sets because only it can recognise
 * its own voice.
 *
 * With more than one connector installed a single Discord message may go to
 * several networks at once, and a single message from one network still fans
 * out to every Discord channel following that room, across unrelated servers.
 * Both directions are bounded — the first by how many connectors are installed,
 * the second by {@see OutboundPacer}.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ChatRelay
{
    /** How many relayed messages to remember for editing, per direction. */
    public const REMEMBER = 500;

    private readonly MessageMap $sent;

    public function __construct(private readonly Bot $bot, int $remember = self::REMEMBER)
    {
        $this->sent = new MessageMap($remember);
    }

    /** Attaches both directions. Call once, after the connectors are up. */
    public function attach(): void
    {
        $this->bot->on('message', fn (Message $message) => $this->fromDiscord($message));

        // MESSAGE_UPDATE hands over a raw payload rather than a Message when
        // the original was never cached, so this is typed `object` and checked.
        $this->bot->on(Event::MESSAGE_UPDATE, fn (object $message) => $this->fromDiscordEdit($message));

        foreach ($this->bot->connectors() as $connector) {
            $connector->onIncoming(fn (Incoming $incoming) => $this->fromConnector($connector, $incoming));
        }
    }

    // ── Discord → everywhere ───────────────────────────────────────────

    private function fromDiscord(Message $message, bool $edited = false): void
    {
        if (! $this->shouldRelayFromDiscord($message)) {
            return;
        }

        $channelId = (string) $message->channel_id;
        $outgoing = $this->compose($message, $edited);

        if ($outgoing->isEmpty()) {
            return;
        }

        foreach ($this->bot->connectors() as $name => $connector) {
            $target = $this->bot->getStore()->links($name)->targetFor($channelId);

            if ($target === null) {
                continue;
            }

            $edited && $this->editOnConnector($connector, $target, $outgoing)
                ? null
                : $this->relayTo($connector, $target, $outgoing);
        }
    }

    private function fromDiscordEdit(object $message): void
    {
        if (! $message instanceof Message) {
            // A partial update — an embed Discord unfurled, a pin, a flag
            // change. There is no content here to relay, and guessing at one
            // would relay an empty message over the top of a real one.
            return;
        }

        $this->fromDiscord($message, edited: true);
    }

    /**
     * Rewrites what this message became on a network that can edit, returning
     * whether it managed to.
     */
    private function editOnConnector(Connector $connector, string $target, Outgoing $outgoing): bool
    {
        if (! $connector instanceof Editing || $outgoing->sourceId === null) {
            return false;
        }

        $key = $connector->name() . ':' . $outgoing->sourceId;
        $remoteId = $this->sent->lookup($key);

        if ($remoteId === null) {
            return false;
        }

        $connector->relay($target, $outgoing)->then(null, function (\Throwable $e) use ($connector): void {
            // Too old to edit, deleted, or refused. Leaving the original alone
            // is better than posting a second copy of it.
            $this->bot->getLogger()->debug(sprintf('[relay] %s edit failed: %s', $connector->name(), $e->getMessage()));
        });

        return true;
    }

    private function relayTo(Connector $connector, string $target, Outgoing $outgoing): void
    {
        $connector->relay($target, $outgoing)->then(
            function (?string $remoteId) use ($connector, $outgoing): void {
                if ($remoteId !== null && $outgoing->sourceId !== null) {
                    $this->sent->remember($connector->name() . ':' . $outgoing->sourceId, $remoteId);
                }
            },
            fn (\Throwable $e) => $this->bot->getLogger()->warning(sprintf(
                '[relay] %s refused a message for %s: %s',
                $connector->name(),
                $target,
                $e->getMessage(),
            )),
        );
    }

    /**
     * Whether a Discord message is real conversation worth relaying.
     *
     * Four rejections, each for its own reason:
     *
     * - **Webhook messages.** Every connector's chat is delivered into Discord
     *   *through* a webhook, so this single check is what stops the loop. It has
     *   to come first and it has to be unconditional.
     * - **Bot authors.** Two bridges sharing a channel would otherwise ping-pong
     *   forever, and a bot's output is rarely what a chat wants to read.
     * - **The bot's own messages**, which is belt-and-braces: command replies
     *   are sent as the bot and would otherwise be echoed out, having already
     *   been said there.
     * - **Commands.** `!twitch title something` is an instruction to the bot,
     *   not a remark; relaying it would put every command into the stream's
     *   chat.
     */
    public function shouldRelayFromDiscord(Message $message): bool
    {
        if (($message->webhook_id ?? null) !== null) {
            return false;
        }

        $author = $message->author ?? null;

        if ($author === null || (bool) ($author->bot ?? false)) {
            return false;
        }

        if ((string) ($author->id ?? '') === (string) ($this->bot->id ?? '')) {
            return false;
        }

        return ! $this->isCommand((string) $message->content, $this->bot->getConfig()->discordPrefix);
    }

    // ── Everywhere → Discord ───────────────────────────────────────────

    private function fromConnector(Connector $connector, Incoming $incoming): void
    {
        // The connector has already dropped its own echo; commands are dropped
        // here so they are handled without also being broadcast.
        if ($incoming->own || $this->isCommand((string) $incoming->text, $this->bot->getConfig()->discordPrefix)) {
            return;
        }

        if ($incoming->isEmpty()) {
            return;
        }

        $surface = $connector->surface();
        $text = $this->renderForDiscord($incoming, $surface->lines);

        if ($text === null) {
            return;
        }

        $channels = $this->bot->getStore()->links($connector->name())->discordFor($incoming->target);
        $suffix = ' (' . $connector->name() . ')';

        foreach ($channels as $channelId) {
            $channel = $this->bot->getChannel($channelId);

            if (! $channel instanceof Channel) {
                $this->bot->getLogger()->debug('[relay] no cached channel ' . $channelId . ' — skipping');

                continue;
            }

            $this->deliver($connector, $channel, $incoming, $text, $suffix);
        }
    }

    private function deliver(
        Connector $connector,
        Channel $channel,
        Incoming $incoming,
        string $text,
        string $suffix,
    ): void {
        $this->bot->delivery()
            ->deliver($channel, $incoming->author, $text, null, $suffix)
            ->then(null, function (\Throwable $e) use ($channel): void {
                $this->bot->getLogger()->warning('[relay] delivery to ' . $channel->id . ' failed: ' . $e->getMessage());

                // Drop the cached webhook. If it was deleted out from under us,
                // every later message would otherwise keep failing against the
                // same dead handle; forgetting it means the next one recreates.
                $this->bot->delivery()->forget($channel);
            });
    }

    /**
     * One incoming message as Discord should show it: the quoted reply, the
     * text, and whatever files came with it.
     */
    private function renderForDiscord(Incoming $incoming, bool $sourceHasLines): ?string
    {
        $parts = [];

        if ($incoming->quoted !== null && trim($incoming->quoted) !== '') {
            $parts[] = '> ' . str_replace("\n", "\n> ", MessageText::truncate(trim($incoming->quoted), 200));
        }

        $body = MessageText::forDiscord((string) $incoming->text, flatten: ! $sourceHasLines);

        if ($body !== null) {
            $parts[] = $body;
        }

        foreach ($incoming->media as $item) {
            $parts[] = $this->describeMedia($item);
        }

        $rendered = trim(implode("\n", array_filter($parts, static fn (string $p): bool => trim($p) !== '')));

        return $rendered === '' ? null : MessageText::truncate($rendered, MessageText::DISCORD_LIMIT);
    }

    /**
     * A file, as a link when there is a safe one and as a name otherwise.
     *
     * A connector hands over `null` for the URL when the only link it could
     * produce carries a credential — a Telegram file URL has the bot token in
     * its path — so this never leaks one into a channel.
     */
    private function describeMedia(Media $item): string
    {
        $name = $item->name ?? ($item->isImage() ? 'an image' : 'a file');

        if ($item->url !== null && MessageText::isRelayableUrl($item->url)) {
            return $item->url;
        }

        return sprintf('-# 📎 %s', MessageText::escapeMarkdown($name));
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * Whether a line is addressed to the bot rather than to the channel.
     *
     * Only a *registered* command counts. Chat is full of `!` — "yes!!!", or
     * another bot's `!drop` — and treating all of it as a command would quietly
     * stop relaying a slice of ordinary conversation.
     */
    public function isCommand(string $content, string $prefix): bool
    {
        if ($prefix === '' || ! str_starts_with($content, $prefix)) {
            return false;
        }

        $rest = trim(substr($content, strlen($prefix)));

        if ($rest === '') {
            return false;
        }

        [$action] = $this->bot->getActions()->resolve(preg_split('/\s+/', $rest) ?: []);

        return $action !== null;
    }

    private function compose(Message $message, bool $edited): Outgoing
    {
        return new Outgoing(
            author: $this->authorName($message),
            text: (string) $message->content,
            userNames: $this->userNames($message),
            channelNames: $this->channelNames($message),
            roleNames: $this->roleNames($message),
            media: $this->attachments($message),
            sourceId: (string) $message->id,
            edited: $edited,
        );
    }

    private function authorName(Message $message): string
    {
        $member = $message->member ?? null;

        return (string) ($member?->nick
            ?? $message->author->displayname
            ?? $message->author->username
            ?? 'someone');
    }

    /**
     * Each attachment as a {@see Media}.
     *
     * `url` rather than `proxy_url`: both are signed and both expire, but `url`
     * is the canonical one, and the proxy adds nothing for a recipient who is
     * going to open it in a browser.
     *
     * @return list<Media>
     */
    private function attachments(Message $message): array
    {
        $media = [];

        foreach ($message->attachments ?? [] as $attachment) {
            $url = (string) ($attachment->url ?? '');

            if ($url === '') {
                continue;
            }

            $media[] = new Media(
                kind: str_starts_with((string) ($attachment->content_type ?? ''), 'image/') ? Media::IMAGE : Media::FILE,
                url: $url,
                id: (string) ($attachment->id ?? ''),
                name: (string) ($attachment->filename ?? ''),
            );
        }

        return $media;
    }

    /** @return array<string, string> */
    private function userNames(Message $message): array
    {
        $names = [];

        foreach ($message->mentions ?? [] as $user) {
            $names[(string) $user->id] = (string) ($user->displayname ?? $user->username ?? 'someone');
        }

        return $names;
    }

    /** @return array<string, string> */
    private function channelNames(Message $message): array
    {
        $names = [];
        $guild = $message->guild ?? null;

        if ($guild === null) {
            return $names;
        }

        foreach ($guild->channels ?? [] as $channel) {
            $names[(string) $channel->id] = (string) $channel->name;
        }

        return $names;
    }

    /** @return array<string, string> */
    private function roleNames(Message $message): array
    {
        $names = [];
        $guild = $message->guild ?? null;

        if ($guild === null) {
            return $names;
        }

        foreach ($guild->roles ?? [] as $role) {
            $names[(string) $role->id] = (string) $role->name;
        }

        return $names;
    }
}

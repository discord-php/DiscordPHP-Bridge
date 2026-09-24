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

use Bridge\Bot;
use Bridge\Capability\Avatars;
use Bridge\Capability\Editing;
use Bridge\Capability\Media as CanSendMedia;
use Bridge\Command\ChatDispatcher;
use Bridge\Command\Surface;
use Bridge\Connector;
use Bridge\Message\Incoming;
use Bridge\Message\Media;
use Bridge\Message\Outgoing;
use Bridge\Support\DiscordMedia;
use Bridge\Support\MessageText;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\WebSockets\Event;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

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
 * the second by {@see OutboundPacer}. A channel bridged to two networks also
 * carries each network's chat to the other, directly, since the Discord copy
 * of it is a webhook message and never relayed onward.
 *
 * ## Edits
 *
 * An edit is only ever applied as an edit. A network that cannot rewrite a
 * message it sent — IRC — never hears about one, because the only alternative
 * is posting the message a second time, and a second copy of everything
 * somebody corrects a typo in is worse than the typo.
 *
 * That matters more than it sounds, because Discord calls a lot of things an
 * edit. When a message containing a link is unfurled into an embed, Discord
 * sends `MESSAGE_UPDATE` with the content unchanged. Treating that as an edit
 * would relay every message with a link in it twice. So an update is acted on
 * only when the text or the attachments actually changed.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class ChatRelay
{
    /** How many relayed messages to remember for editing, per direction. */
    public const REMEMBER = 500;

    /** @var MessageMap "connector:discordId" => the id it was given on that network */
    private readonly MessageMap $sent;

    /** @var MessageMap "discordId" => fingerprint of what was relayed, to tell an edit from an unfurl */
    private readonly MessageMap $seen;

    /** @var MessageMap "connector:room:id" => list of Discord copies it became */
    private readonly MessageMap $delivered;

    /** @var array<string, ChatDispatcher> connector name => its command matcher */
    private array $commands = [];

    public function __construct(private readonly Bot $bot, int $remember = self::REMEMBER)
    {
        $this->sent = new MessageMap($remember);
        $this->seen = new MessageMap($remember);
        $this->delivered = new MessageMap($remember);
    }

    /** Attaches both directions. Call once; nothing arrives until a connector starts. */
    public function attach(): void
    {
        $this->bot->on('message', fn (Message $message) => $this->fromDiscord($message));

        // MESSAGE_UPDATE hands over a raw payload rather than a Message when
        // the original was never cached, so this is typed `object` and checked.
        $this->bot->on(Event::MESSAGE_UPDATE, fn (object $message) => $this->fromDiscordEdit($message));

        foreach ($this->bot->connectors() as $connector) {
            $this->commands[$connector->name()] = new ChatDispatcher($this->bot, $connector);
            $connector->onIncoming(fn (Incoming $incoming) => $this->fromConnector($connector, $incoming));
        }
    }

    // ── Discord → everywhere ───────────────────────────────────────────

    private function fromDiscord(Message $message): void
    {
        // Bridged at all, first. The maps below are bounded, and remembering
        // every message in every channel would let one busy unbridged channel
        // evict what the bridged ones need to follow an edit.
        $bridged = $this->bridgedConnectors((string) $message->channel_id);

        if ($bridged === [] || ! $this->shouldRelayFromDiscord($message)) {
            return;
        }

        $outgoing = $this->compose($message, edited: false);

        if ($outgoing->isEmpty()) {
            return;
        }

        $this->seen->remember((string) $message->id, self::fingerprint($message));

        foreach ($bridged as [$connector, $target]) {
            $this->relayTo($connector, $target, $outgoing);
        }
    }

    /**
     * An edit of something already relayed, and only that.
     *
     * Three things are not edits and are dropped: a partial update with no
     * message part (a pin, a flag change), an update to a message this bot
     * never relayed, and an update whose content did not change — which is
     * what an unfurling link looks like.
     */
    private function fromDiscordEdit(object $message): void
    {
        if (! $message instanceof Message || ! $this->shouldRelayFromDiscord($message)) {
            return;
        }

        $id = (string) $message->id;
        $before = $this->seen->lookup($id);
        $now = self::fingerprint($message);

        if ($before === null || $before === $now) {
            return;
        }

        $this->seen->remember($id, $now);
        $outgoing = $this->compose($message, edited: true);

        foreach ($this->bridgedConnectors((string) $message->channel_id) as [$connector, $target]) {
            if (! $connector instanceof Editing) {
                continue;
            }

            $remoteId = $this->sent->lookup($connector->name() . ':' . $id);

            if ($remoteId === null) {
                continue;
            }

            $connector->edit($target, (string) $remoteId, $outgoing)->then(
                null,
                // Too old to edit, deleted, or refused. Leaving the original
                // alone is better than posting a second copy of it.
                fn (\Throwable $e) => $this->bot->getLogger()->debug(sprintf(
                    '[relay] %s would not take an edit: %s',
                    $connector->name(),
                    $e->getMessage(),
                )),
            );
        }
    }

    private function relayTo(Connector $connector, string $target, Outgoing $outgoing): void
    {
        $send = $connector instanceof CanSendMedia && ($photo = $this->photoIn($outgoing)) !== null
            ? $this->relayPhoto($connector, $target, $photo, $outgoing)
            : $connector->relay($target, $outgoing);

        $send->then(
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
     * Hands the picture to a network that can carry it, and falls back to a
     * link in the text when it will not take it.
     *
     * A network that can carry the picture should, because then it is actually
     * there — a relayed link to Discord's CDN expires in about a day, so it is
     * dead by the time anyone reads the logs.
     *
     * @return PromiseInterface<?string>
     */
    private function relayPhoto(CanSendMedia&Connector $connector, string $target, Media $photo, Outgoing $outgoing): PromiseInterface
    {
        return $connector->sendMedia($target, $photo, $outgoing)->then(
            null,
            function (\Throwable $e) use ($connector, $target, $outgoing): PromiseInterface {
                $this->bot->getLogger()->debug(sprintf(
                    '[relay] %s would not take the picture, sending it as a link: %s',
                    $connector->name(),
                    $e->getMessage(),
                ));

                return $connector->relay($target, $outgoing);
            },
        );
    }

    /**
     * The one picture worth handing to the network itself, if there is one.
     *
     * Only the first: sending several means a media group, which is a different
     * call on every network that has one, and mixing files with images is not
     * something they agree on. The rest relay as links in the text.
     */
    private function photoIn(Outgoing $outgoing): ?Media
    {
        foreach ($outgoing->media as $item) {
            if ($item->isImage() && $item->url !== null && MessageText::isRelayableUrl($item->url)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Every connector this Discord channel is bridged through, and the room on
     * each.
     *
     * @return list<array{0: Connector, 1: string}>
     */
    private function bridgedConnectors(string $channelId): array
    {
        $bridged = [];

        foreach ($this->bot->connectors() as $name => $connector) {
            $target = $this->bot->getStore()->links($name)->targetFor($channelId);

            if ($target !== null) {
                $bridged[] = [$connector, $target];
            }
        }

        return $bridged;
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

        return ! $this->isDiscordCommand((string) $message->content);
    }

    // ── Everywhere → Discord ───────────────────────────────────────────

    private function fromConnector(Connector $connector, Incoming $incoming): void
    {
        // The connector has already dropped its own echo; commands are dropped
        // here so they are answered without also being broadcast. Recognised by
        // *that network's* prefix, which need not be Discord's.
        if ($incoming->own || $incoming->isEmpty()) {
            return;
        }

        if (($this->commands[$connector->name()] ?? null)?->isCommand((string) $incoming->text)) {
            return;
        }

        $this->acrossNetworks($connector, $incoming);

        $channels = $this->channelsFor($connector, $incoming);

        if ($channels === []) {
            return;
        }

        if ($incoming->edited && $incoming->id !== null && $this->editInDiscord($connector, $incoming)) {
            return;
        }

        $mirror = $this->mirrorableIn($connector, $incoming);

        all([
            $mirror === null ? resolve(null) : $this->fetch($connector, $mirror),
            $connector instanceof Avatars
                ? $connector->avatarFor($incoming)->then(null, static fn (): ?string => null)
                : resolve(null),
        ])->then(function (array $fetched) use ($connector, $incoming, $channels, $mirror): void {
            [$file, $avatar] = $fetched;

            // A file that made it across is shown as itself, not named as well.
            $text = $this->renderForDiscord($incoming, $connector->surface()->lines, $file === null ? null : $mirror);

            if ($text === null && $file === null) {
                return;
            }

            foreach ($channels as $channel) {
                $this->deliver($connector, $channel, $incoming, $text, $avatar, $file);
            }
        });
    }

    /**
     * Hands a message on to every *other* network bridged to the same Discord
     * channels, so a channel bridged to Twitch and Telegram is one conversation
     * across all three rather than two that only Discord can see.
     *
     * Discord cannot make this hop itself: the copy it receives arrives through
     * a webhook, and webhook messages are dropped unconditionally because that
     * is what stops the loop. So it is made here, from the original.
     *
     * Each room gets the message once however many channels lead to it, and an
     * edit follows the same rule as everywhere else — applied as an edit where
     * the network can and the copy is remembered, otherwise not at all.
     */
    private function acrossNetworks(Connector $source, Incoming $incoming): void
    {
        $key = self::incomingKey($source, $incoming);
        $outgoing = null;
        $reached = [];

        foreach ($this->bot->getStore()->links($source->name())->discordFor($incoming->target) as $channelId) {
            foreach ($this->bridgedConnectors((string) $channelId) as [$connector, $target]) {
                $room = $connector->name() . "\0" . $target;

                if ($connector === $source || isset($reached[$room])) {
                    continue;
                }

                $reached[$room] = true;
                $outgoing ??= $this->composeFromNetwork($source, $incoming, $key);

                if ($outgoing->isEmpty()) {
                    return;
                }

                if (! $incoming->edited) {
                    $this->relayTo($connector, $target, $outgoing);

                    continue;
                }

                $remoteId = $this->sent->lookup($connector->name() . ':' . $key);

                if ($connector instanceof Editing && $remoteId !== null) {
                    $connector->edit($target, (string) $remoteId, $outgoing)->then(
                        null,
                        fn (\Throwable $e) => $this->bot->getLogger()->debug(sprintf(
                            '[relay] %s would not take an edit: %s',
                            $connector->name(),
                            $e->getMessage(),
                        )),
                    );
                }
            }
        }
    }

    /**
     * A message from one network as another should receive it.
     *
     * Named with the network it came from, as a Discord copy is. A file only
     * the source network can open — a Telegram photo has no URL that does not
     * carry the bot token — goes as a link to the public page showing it when
     * there is one, and is named otherwise.
     */
    private function composeFromNetwork(Connector $source, Incoming $incoming, string $key): Outgoing
    {
        $text = trim((string) $incoming->text);
        $media = [];

        foreach ($incoming->media as $item) {
            if ($item->url !== null && MessageText::isRelayableUrl($item->url)) {
                $media[] = $item;

                continue;
            }

            // Carried like a file's own link, so a text-only network shows it
            // the same way. As a file, not an image: it is a page, and a
            // network that sends pictures must link it rather than post it.
            if ($item->link !== null && MessageText::isRelayableUrl($item->link)) {
                $media[] = new Media(Media::FILE, $item->link, name: $item->name, caption: $item->caption);

                continue;
            }

            $text = ltrim($text . ' 📎 ' . ($item->name ?? ($item->isImage() ? 'a photo' : 'a file')));
        }

        return new Outgoing(
            author: $incoming->author . $this->suffix($source),
            text: $text,
            media: $media,
            sourceId: $incoming->id === null ? null : $key,
            edited: $incoming->edited,
        );
    }

    /**
     * Every cached Discord channel following the room a message came from.
     *
     * @return list<Channel>
     */
    private function channelsFor(Connector $connector, Incoming $incoming): array
    {
        $channels = [];

        foreach ($this->bot->getStore()->links($connector->name())->discordFor($incoming->target) as $channelId) {
            $channel = $this->bot->getChannel($channelId);

            if ($channel instanceof Channel) {
                $channels[] = $channel;
            } else {
                $this->bot->getLogger()->debug('[relay] no cached channel ' . $channelId . ' — skipping');
            }
        }

        return $channels;
    }

    /**
     * Rewrites every Discord copy of an edited message, returning whether any
     * were remembered.
     *
     * When none are — the original was relayed before the last restart, or so
     * long ago it fell out of memory — the edit is relayed as a new message
     * rather than lost, which on a network that sends whole messages as edits
     * is the only way the correction reaches anyone.
     */
    private function editInDiscord(Connector $connector, Incoming $incoming): bool
    {
        $copies = $this->delivered->lookup(self::incomingKey($connector, $incoming));

        if (! is_array($copies) || $copies === []) {
            return false;
        }

        $text = $this->renderForDiscord($incoming, $connector->surface()->lines);

        if ($text === null) {
            return true;
        }

        foreach ($copies as $copy) {
            $channel = $this->bot->getChannel($copy['channel_id']);

            if (! $channel instanceof Channel) {
                continue;
            }

            $this->bot->delivery()
                ->edit($channel, $copy['message_id'], $copy['via'], $incoming->author, $text, $this->suffix($connector))
                ->then(null, fn (\Throwable $e) => $this->bot->getLogger()->debug(
                    '[relay] could not edit the copy in ' . $copy['channel_id'] . ': ' . $e->getMessage(),
                ));
        }

        return true;
    }

    /**
     * @param array{filename: string, content: string}|null $file
     */
    private function deliver(
        Connector $connector,
        Channel $channel,
        Incoming $incoming,
        ?string $text,
        ?string $avatar,
        ?array $file,
    ): void {
        $this->bot->delivery()
            ->deliver($channel, $incoming->author, $text, $avatar, $this->suffix($connector), $file)
            ->then(
                function (array $copy) use ($connector, $incoming, $channel): void {
                    if ($incoming->id === null) {
                        return;
                    }

                    $key = self::incomingKey($connector, $incoming);
                    $copies = $this->delivered->lookup($key);
                    $copies = is_array($copies) ? $copies : [];
                    $copies[] = $copy + ['channel_id' => (string) $channel->id];

                    $this->delivered->remember($key, $copies);
                },
                function (\Throwable $e) use ($channel): void {
                    $this->bot->getLogger()->warning('[relay] delivery to ' . $channel->id . ' failed: ' . $e->getMessage());

                    // Drop the cached webhook. If it was deleted out from under
                    // us, every later message would otherwise keep failing
                    // against the same dead handle; forgetting it means the
                    // next one recreates.
                    $this->bot->delivery()->forget($channel);
                },
            );
    }

    /**
     * The one attachment worth copying across as a file.
     *
     * Only one, and only from a connector that can fetch it: a second upload
     * per message multiplies the bytes by every channel it fans out to.
     */
    private function mirrorableIn(Connector $connector, Incoming $incoming): ?Media
    {
        if (! $connector instanceof CanSendMedia) {
            return null;
        }

        foreach ($incoming->media as $item) {
            if ($item->id !== null && $item->url === null) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return PromiseInterface<array{filename: string, content: string}|null>
     */
    private function fetch(Connector $connector, Media $media): PromiseInterface
    {
        if (! $connector instanceof CanSendMedia) {
            return resolve(null);
        }

        return $connector->fetchMedia($media)->then(
            null,
            function (\Throwable $e) use ($connector): mixed {
                // Deliberately not the exception's message: it can quote the
                // request, and on at least one network the request URL is a
                // credential.
                $this->bot->getLogger()->warning(sprintf('[relay] could not fetch a file from %s', $connector->name()));

                return null;
            },
        );
    }

    /**
     * One incoming message as Discord should show it: the quoted reply, the
     * text, and whatever files came with it.
     *
     * @param ?Media $mirrored An attachment that is being uploaded, so is not named as well.
     */
    private function renderForDiscord(Incoming $incoming, bool $sourceHasLines, ?Media $mirrored = null): ?string
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
            if ($item !== $mirrored) {
                $parts[] = $this->describeMedia($item);
            }
        }

        $rendered = trim(implode("\n", array_filter($parts, static fn (string $p): bool => trim($p) !== '')));

        return $rendered === '' ? null : MessageText::truncate($rendered, MessageText::DISCORD_LIMIT);
    }

    /**
     * A file, as a link when there is a safe one and as a name otherwise.
     *
     * A connector hands over `null` for the URL when the only link it could
     * produce carries a credential — a Telegram file URL has the bot token in
     * its path — so this never leaks one into a channel. A public page showing
     * the file is the next best thing: reached when a file was too big to copy
     * across, or the copy failed.
     */
    private function describeMedia(Media $item): string
    {
        $name = $item->name ?? ($item->isImage() ? 'an image' : 'a file');

        if ($item->url !== null && MessageText::isRelayableUrl($item->url)) {
            return $item->url;
        }

        if ($item->link !== null && MessageText::isRelayableUrl($item->link)) {
            return $item->link;
        }

        return sprintf('-# 📎 %s', MessageText::escapeMarkdown($name));
    }

    /** Appended to a relayed name, so it is never mistaken for a Discord account. */
    private function suffix(Connector $connector): string
    {
        return ' (' . $connector->name() . ')';
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * Whether a Discord message is addressed to the bot rather than to the
     * channel.
     *
     * Only a *registered* command counts. Chat is full of `!` — "yes!!!", or
     * another bot's `!drop` — and treating all of it as a command would quietly
     * stop relaying a slice of ordinary conversation.
     */
    public function isDiscordCommand(string $content): bool
    {
        $prefix = $this->bot->getConfig()->discordPrefix;

        if ($prefix === '' || ! str_starts_with($content, $prefix)) {
            return false;
        }

        $rest = trim(substr($content, strlen($prefix)));

        if ($rest === '') {
            return false;
        }

        $words = preg_split('/\s+/', $rest) ?: [];

        return $this->bot->getActions()->resolve($words)[0] !== null
            || (count($words) === 1 && in_array(strtolower($words[0]), $this->bot->getActions()->qualifiers(), true));
    }

    /**
     * What a relayed message looked like, for telling a real edit from an
     * update that changed nothing a chat can see.
     */
    public static function fingerprint(Message $message): string
    {
        $attachments = [];

        foreach ($message->attachments ?? [] as $attachment) {
            $attachments[] = (string) ($attachment->id ?? '');
        }

        return md5((string) $message->content . "\0" . implode(',', $attachments));
    }

    /**
     * Where a message from a network is remembered. Room and id together,
     * because a message id is only unique within its room on some networks —
     * Telegram numbers each chat from one.
     */
    private static function incomingKey(Connector $connector, Incoming $incoming): string
    {
        return $connector->name() . ':' . $incoming->target . ':' . (string) $incoming->id;
    }

    private function compose(Message $message, bool $edited): Outgoing
    {
        $text = (string) $message->content;
        $media = $this->attachments($message);

        // A message that is nothing but a link to a picture on Discord's CDN —
        // what the GIF picker sends — is that picture, as Discord shows it.
        $linked = $media === [] ? DiscordMedia::fromUrl(trim($text)) : null;

        if ($linked !== null) {
            $text = '';
            $media = [$linked];
        }

        return new Outgoing(
            // Labelled with where it came from, as a relayed Twitch or Telegram
            // message is, so a Discord member is never taken for someone in
            // that chat — on Twitch, every relayed line is spoken by the host.
            author: $this->authorName($message) . ' (' . Surface::DISCORD . ')',
            text: $text,
            userNames: $this->userNames($message),
            channelNames: $this->channelNames($message),
            roleNames: $this->roleNames($message),
            media: $media,
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
                size: isset($attachment->size) ? (int) $attachment->size : null,
                mimeType: (string) ($attachment->content_type ?? '') ?: null,
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

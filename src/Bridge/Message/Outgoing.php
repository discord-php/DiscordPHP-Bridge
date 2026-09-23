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
 * A Discord message on its way out to another network, before anyone has
 * decided what it should look like there.
 *
 * Text is the raw Discord content, mention markup and all, with the lookup
 * tables needed to render `<@1234>` as a name. Nothing is escaped or truncated
 * yet, because what has to be escaped and how much will fit are the connector's
 * business: IRC cannot carry a newline, Telegram's HTML mode needs three
 * characters escaped and no more, and the limits are 500 and 4096.
 *
 * So the relay builds one of these and the connector renders it — using
 * {@see \Bridge\Support\MessageText}, which holds the parts that are the same
 * everywhere.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Outgoing
{
    /**
     * @param string                $author       Display name, already resolved from the member or user.
     * @param string                $text         Raw Discord content, mention markup included.
     * @param array<string, string> $userNames    Discord user id => display name, for `<@id>`.
     * @param array<string, string> $channelNames Discord channel id => name, for `<#id>`.
     * @param array<string, string> $roleNames    Discord role id => name, for `<@&id>`.
     * @param list<Media>           $media        Attachments, in the order posted.
     * @param ?string               $sourceId     The Discord message id, for mapping a later edit.
     * @param bool                  $edited       Whether this revises a message already relayed.
     */
    public function __construct(
        public readonly string $author,
        public readonly string $text = '',
        public readonly array $userNames = [],
        public readonly array $channelNames = [],
        public readonly array $roleNames = [],
        public readonly array $media = [],
        public readonly ?string $sourceId = null,
        public readonly bool $edited = false,
    ) {
    }

    /** Whether there is anything at all worth relaying. */
    public function isEmpty(): bool
    {
        return trim($this->text) === '' && $this->media === [];
    }

    /**
     * The attachment URLs, in order, for a connector that can only relay links.
     *
     * @return list<string>
     */
    public function mediaUrls(): array
    {
        $urls = [];

        foreach ($this->media as $item) {
            if ($item->url !== null && $item->url !== '') {
                $urls[] = $item->url;
            }
        }

        return $urls;
    }

    /**
     * The same message without one attachment.
     *
     * For a network that sends a picture as a picture and the text as its
     * caption: the caption should not then link to the very image it sits
     * under.
     */
    public function withoutMedia(Media $sent): self
    {
        return new self(
            $this->author,
            $this->text,
            $this->userNames,
            $this->channelNames,
            $this->roleNames,
            array_values(array_filter($this->media, static fn (Media $m): bool => $m !== $sent)),
            $this->sourceId,
            $this->edited,
        );
    }
}

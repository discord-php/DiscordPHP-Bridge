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
 * One message, as it arrived from a connector's network.
 *
 * The narrow waist of the whole project: a connector turns its own library's
 * message — a `Twitch\Parts\ChatMessage`, a `Telegram\Parts\Message` — into one
 * of these, and from there nothing downstream knows or cares which network it
 * came from. {@see \Bridge\Relay\ChatRelay} routes it, {@see \Bridge\Command\Action}
 * handlers answer it, and neither has a platform's name in it.
 *
 * Text is already the *content* of the message: whatever wrapping the platform
 * puts around it has been removed, and whatever this bot adds on the way out is
 * added later. It is **not** sanitised — the destination decides that, because
 * what IRC cannot carry and what Telegram's HTML mode cannot carry are
 * different sets.
 *
 * It can also be a notice that something is gone ({@see $deleted}): a message
 * deleted where it was said, or everything an author said, after a ban. The
 * relay then removes the Discord copies it still remembers. Build those with
 * {@see deletion()} and {@see purge()}.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Incoming
{
    /**
     * @param string       $target    The room it was said in, as {@see \Bridge\Links} keys them.
     * @param string       $author    A display name, already resolved.
     * @param ?string      $authorId  The platform's own id for the author.
     * @param ?string      $text      `null` when the message carried no text at all.
     * @param ?string      $id        The platform's message id, when it has one that can be edited.
     * @param ?string      $quoted    A reply's quoted text, already rendered for display.
     * @param list<Media>  $media     Pictures and files, in the order they were attached.
     * @param bool         $edited    Whether this is a revision of a message already relayed.
     * @param bool         $own       Whether the bot itself said it — the loop-prevention check.
     * @param ?string      $handle    The author's login or username, where the network
     *                                has one distinct from the display name — what an
     *                                avatar lookup is keyed by.
     * @param bool         $deleted   Not a message but a notice that one is gone: the one with
     *                                `$id`, or with no id, everything recent by `$authorId`.
     */
    public function __construct(
        public readonly string $target,
        public readonly string $author,
        public readonly ?string $authorId = null,
        public readonly ?string $text = null,
        public readonly ?string $id = null,
        public readonly ?string $quoted = null,
        public readonly array $media = [],
        public readonly bool $edited = false,
        public readonly bool $own = false,
        public readonly ?string $handle = null,
        public readonly bool $deleted = false,
    ) {
    }

    /**
     * The message with this id was deleted where it was said, so its Discord
     * copies go too.
     */
    public static function deletion(string $target, string $id): self
    {
        return new self($target, '', id: $id, deleted: true);
    }

    /**
     * Everything this author said in the room recently is to go: they were
     * banned, and what they said with it. Only what the relay still remembers
     * can be found, which is the recent past.
     */
    public static function purge(string $target, string $authorId, string $author = ''): self
    {
        return new self($target, $author, authorId: $authorId, deleted: true);
    }

    /** Whether there is anything at all worth relaying. A deletion never is. */
    public function isEmpty(): bool
    {
        return $this->deleted || (($this->text === null || trim($this->text) === '') && $this->media === []);
    }
}

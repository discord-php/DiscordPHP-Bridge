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

namespace Bridge\Capability;

use Bridge\Message\Outgoing;
use React\Promise\PromiseInterface;

/**
 * A connector whose network lets a message already sent be rewritten.
 *
 * Telegram does; IRC does not, and no amount of wishing makes it. A relay that
 * assumed every network could edit would have to fall back to posting a second
 * copy on the ones that cannot, which is worse than leaving the original alone
 * — so the relay asks instead of assuming, and a network without this
 * capability simply never hears about an edit.
 *
 * Implementing this is a promise that {@see \Bridge\Connector::relay()}
 * resolves to the id of what it sent, because that id is what an edit is
 * addressed to.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface Editing
{
    /**
     * Rewrites a message this bot relayed, to what the Discord original now
     * says.
     *
     * The whole {@see Outgoing} comes rather than finished text, for the same
     * reason {@see \Bridge\Connector::relay()} takes one: rendering it is the
     * connector's business.
     *
     * Rejects when the message is too old to edit, was deleted, or the network
     * refuses for its own reasons; the caller leaves it be rather than retry.
     */
    public function edit(string $target, string $messageId, Outgoing $message): PromiseInterface;
}

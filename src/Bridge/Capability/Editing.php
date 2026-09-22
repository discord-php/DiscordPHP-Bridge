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

namespace Bridge\Capability;

use React\Promise\PromiseInterface;

/**
 * A connector whose network lets a message already sent be rewritten.
 *
 * Telegram does; IRC does not, and no amount of wishing makes it. A relay that
 * assumed every network could edit would have to fall back to posting a second
 * "(edited)" message on the ones that cannot, which is worse than leaving the
 * original alone — so the relay asks instead of assuming.
 *
 * Implementing this is a promise that {@see \Bridge\Message\Incoming::$id} is
 * populated for anything sent through this connector, because that id is what
 * an edit is addressed to.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
interface Editing
{
    /**
     * Rewrites a message this bot sent.
     *
     * Rejects when the message is too old to edit, was deleted, or the network
     * refuses for its own reasons; the caller is expected to leave it be rather
     * than retry.
     */
    public function edit(string $target, string $messageId, string $text): PromiseInterface;
}

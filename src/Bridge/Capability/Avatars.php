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

use Bridge\Message\Incoming;
use React\Promise\PromiseInterface;

/**
 * A connector that can say what somebody looks like.
 *
 * Relayed chat arrives in Discord through a webhook, which can carry any name
 * and any picture per message. Without a picture every line from a network
 * wears the same default one, and a busy chat becomes hard to follow — who
 * said what is carried almost entirely by the avatar.
 *
 * Optional, and deliberately so: a network whose profile pictures are only
 * reachable through a URL carrying the bot token must not implement this, and
 * its relayed lines go without rather than publishing a credential.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface Avatars
{
    /**
     * A public URL for the author's picture, or `null` when there is none or
     * the lookup failed.
     *
     * Never rejects: a missing picture is a cosmetic loss, and must not cost
     * the message.
     *
     * @return PromiseInterface<?string>
     */
    public function avatarFor(Incoming $message): PromiseInterface;
}

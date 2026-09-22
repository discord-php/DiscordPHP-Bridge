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

use Bridge\Command\Action;

/**
 * A connector that brings commands of its own.
 *
 * Twitch brings the whole Helix API; Telegram brings sending, polls and
 * moderation. Both arrive in the one catalogue, under the connector's own
 * qualifier, and are then reachable from *every* chat the bot is in — which is
 * the point of one bot rather than two: `!twitch title` typed in a Telegram
 * group sets the stream title.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface ProvidesActions
{
    /**
     * Every command this connector defines.
     *
     * Each one's qualifier must be the connector's own {@see \Bridge\Connector::name()};
     * {@see \Bridge\Command\ActionRegistry} refuses anything else, because a
     * connector claiming another's qualifier is how two packages end up
     * silently shadowing each other's commands.
     *
     * @return list<Action>
     */
    public function actions(): array;
}

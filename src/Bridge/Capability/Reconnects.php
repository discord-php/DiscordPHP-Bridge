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

use React\Promise\PromiseInterface;

/**
 * A connector whose connection can drop while the bot runs, and be tried again
 * on request.
 *
 * The connector keeps its own connection: it notices a drop, retries and backs
 * off. What it cannot do alone is reach a person once retrying stops working.
 * It reports that to {@see \Bridge\Support\ConnectionAlerts::failed()}, and the
 * connection's return to {@see \Bridge\Support\ConnectionAlerts::restored()}.
 * The bot DMs its owner, with a button that calls {@see reconnect()}.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface Reconnects
{
    /**
     * Tries to connect again now, whatever the connector's own retrying is doing.
     *
     * @return PromiseInterface<mixed> Resolves once connected, at once when
     *                                 already connected. Rejects when this
     *                                 attempt fails; the retrying carries on.
     */
    public function reconnect(): PromiseInterface;
}

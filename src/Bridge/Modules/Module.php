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

namespace Bridge\Modules;

use Bridge\Bot;

/**
 * A self-contained Discord-side feature that attaches its own listeners once
 * the gateway is ready.
 *
 * This is the escape hatch from {@see \Bridge\Command\Action}, which is a
 * string in and a string out. That covers what a chat command is, and nothing
 * else: a Components v2 panel whose buttons stay live, or a feature driven by a
 * Discord event rather than by somebody typing, needs the whole `Discord`
 * object — so it is a module instead.
 *
 * The shape is the same one the rest of the DiscordPHP family uses, so the
 * projects stay legible to each other.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
interface Module
{
    /** Stable short name, used for logging. */
    public function name(): string;

    /** Attach listeners, timers, and slash-command handlers. Called once, after ready. */
    public function boot(Bot $bot): void;
}

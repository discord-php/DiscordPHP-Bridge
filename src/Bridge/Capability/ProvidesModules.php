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

use Bridge\Modules\Module;

/**
 * A connector that brings Discord-side features an {@see \Bridge\Command\Action}
 * cannot express.
 *
 * An action is a string in and a string out, which covers most of what a chat
 * command is. What it cannot do is a Components v2 panel with buttons that stay
 * live, or anything driven by a Discord event rather than by somebody typing —
 * so a connector that wants those provides a {@see Module} instead, and gets
 * the whole `Discord` object to work against.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
interface ProvidesModules
{
    /** @return list<Module> */
    public function modules(): array;
}

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

namespace Bridge\Command;

/**
 * How an {@see Action} presents itself as a Discord slash command.
 *
 * Slash commands need something prefix commands do not: a declared, typed
 * parameter list, so Discord can render a picker and reject bad input before
 * the bot ever sees it. Rather than describe every action twice, an action
 * opts in by attaching one of these, and {@see SlashAdapter} turns it into the
 * real thing — reusing the same handler, unchanged.
 *
 * There is no sub-command list here, and deliberately so: an action *is* a
 * leaf. Where it sits in the tree is its qualifier and group, and a command
 * with children is something {@see SlashAdapter} assembles out of several
 * actions rather than something one action declares.
 *
 * Actions without a spec are still reachable by prefix in every chat; they
 * simply do not appear in Discord's command menu.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Slash
{
    /**
     * @param list<SlashOption> $options   This command's typed parameters.
     * @param bool              $ephemeral Whether the reply is shown only to the invoker.
     */
    public function __construct(
        public readonly array $options = [],
        public readonly bool $ephemeral = false,
    ) {
    }
}

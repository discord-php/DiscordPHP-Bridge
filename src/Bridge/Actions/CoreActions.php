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

namespace Bridge\Actions;

use Bridge\Bot;
use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Support\Format;

/**
 * `/bridge` — the commands that are about the bot rather than about any one
 * network.
 *
 * `help` and `about` would be the obvious names, and that is exactly the
 * problem: they are the names every bot in a server wants. Qualifying them is
 * not pedantry here, it is the same rule that keeps two connectors from
 * colliding, applied to the core so that it cannot be the exception.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class CoreActions implements ProvidesActions
{
    /** The qualifier the core itself owns. No connector may claim it. */
    public const QUALIFIER = 'bridge';

    /** @return list<Action> */
    public function actions(): array
    {
        return [
            new Action(
                self::QUALIFIER,
                'help',
                $this->help(...),
                'List what you can run, or explain one command',
                '[command]',
                slash: new Slash([
                    new SlashOption('command', 'A command to explain, e.g. "twitch link".'),
                ], ephemeral: true),
            ),
            new Action(
                self::QUALIFIER,
                'about',
                $this->about(...),
                'What this bot is and where it came from',
                slash: new Slash(ephemeral: true),
            ),
            new Action(
                self::QUALIFIER,
                'list',
                $this->list(...),
                'Every bridge this server has, on every network',
                access: Access::Administrator,
                only: 'discord',
                slash: new Slash(ephemeral: true),
            ),
            new Action(
                self::QUALIFIER,
                'status',
                $this->status(...),
                'What the bot is connected to, and what the last check found',
                slash: new Slash(ephemeral: true),
            ),
        ];
    }

    private function help(Context $context, Arguments $arguments): string
    {
        $wanted = trim((string) ($arguments->named('command') ?? implode(' ', $arguments->all())));

        if ($wanted !== '') {
            $action = $context->bot->getActions()->get($wanted)
                ?? $context->bot->getActions()->resolve(preg_split('/\s+/', $wanted) ?: [])[0];

            if ($action === null) {
                return sprintf('nothing here is called `%s`. Try `help` on its own.', mb_substr($wanted, 0, 60));
            }

            return $action->help(
                $context->surface->isDiscord() ? '/' : $context->bot->getConfig()->discordPrefix,
                full: $context->surface->isDiscord(),
            );
        }

        $prefix = $context->surface->isDiscord() ? '/' : $context->bot->getConfig()->discordPrefix;
        $lines = [];

        foreach ($context->bot->getActions()->grouped($context->surface, $context->access) as $heading => $actions) {
            $names = array_map(static fn (Action $a): string => $a->name, $actions);
            $lines[] = sprintf('%s%s — %s', $prefix, $heading, implode(', ', $names));
        }

        return Format::listing($context->surface, $lines, 'What you can run', 'nothing you can run here.');
    }

    private function about(Context $context): string
    {
        $connectors = [];

        foreach ($context->bot->connectors() as $connector) {
            $connectors[] = $connector->label();
        }

        return Format::fields($context->surface, [
            'bridging' => $connectors === [] ? 'nothing yet' : implode(', ', $connectors),
            'bridges' => $context->bot->getStore()->count(),
            'commands' => $context->bot->getActions()->count(),
            'source' => Bot::GITHUB,
        ], 'About');
    }

    private function list(Context $context): string
    {
        $guildId = $context->requireGuild();
        $store = $context->bot->getStore();
        $items = [];

        foreach ($store->connectors() as $connector) {
            foreach ($store->links($connector)->forGuild($guildId) as $channelId => $target) {
                $items[] = sprintf(
                    '<#%s> ⇄ `%s` **%s**',
                    $channelId,
                    $connector,
                    $store->label($connector, $target) ?? $target,
                );
            }
        }

        return Format::listing(
            $context->surface,
            $items,
            'Bridges in this server',
            'none yet — each network has its own `link`.',
        );
    }

    private function status(Context $context): string
    {
        $fields = ['bridges' => $context->bot->getStore()->count()];

        foreach ($context->bot->connectors() as $name => $connector) {
            $fields[$name] = sprintf(
                '%d room(s), %d queued',
                count($connector->joined()),
                $connector->queued(),
            );
        }

        $fields['discord queue'] = $context->bot->pacer()->queued();
        $fields['last check'] = $context->bot->getLastCheck();
        $fields['storage'] = $context->bot->getStore()->filesystem()->describe();

        return Format::fields($context->surface, $fields, 'Status');
    }
}

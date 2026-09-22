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

namespace Bridge\Command;

use React\Promise\PromiseInterface;

/**
 * One command, declared once and registered into every chat the bot is in.
 *
 * The handler receives a {@see Context} and {@see Arguments} and returns the
 * reply — a string, `null` for "say nothing", or a promise of either. It is
 * never handed a `Message`, a `ChatMessage` or an `Interaction`, which is what
 * keeps a single definition serviceable from platforms that agree on almost
 * nothing.
 *
 * ## Every command is qualified
 *
 * A command has three parts: the **qualifier**, which is the connector that
 * owns it; an optional **group**; and the **name**.
 *
 * ```
 * twitch · channel · title
 * ```
 *
 * The qualifier is not decoration. A name is only free because no connector has
 * claimed it yet — `title` belongs to Twitch today and to something else the
 * moment a fourth network arrives — and since every connector's commands are
 * offered on *every* surface, an unqualified catalogue is one package away from
 * two commands answering to the same word. Qualifying makes that impossible by
 * construction rather than by luck.
 *
 * Each surface renders the parts it has room for:
 *
 * | Surface        | Form                          |
 * | -------------- | ----------------------------- |
 * | Discord slash  | `/twitch channel title`       |
 * | Discord prefix | `!twitch channel title`       |
 * | Any other chat | `!twitch title`               |
 *
 * Discord caps a command at 25 options, which is why the group exists at all;
 * a chat has no such cap, so it drops the group and stays short. It never drops
 * the qualifier, and {@see ActionRegistry} refuses two actions that would
 * flatten to the same thing.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class Action
{
    /** @var \Closure(Context, Arguments): (string|null|PromiseInterface) */
    private readonly \Closure $handler;

    /**
     * @param string                                                       $qualifier  The owning connector's name, or `bridge` for the core's own.
     * @param string                                                       $name       The leaf name, unique within the qualifier.
     * @param callable(Context, Arguments): (string|null|PromiseInterface) $handler
     * @param ?string                                                      $group      Groups it under a slash sub-command group, and in `help`.
     * @param list<string>                                                 $aliases    Alternative leaf names, within the same qualifier.
     * @param ?string                                                      $only       Restrict to one surface by name; `null` for every surface.
     * @param bool                                                         $sensitive  Whether the reply may contain a secret.
     * @param ?Slash                                                       $slash      Opt in to a Discord slash command.
     */
    public function __construct(
        public readonly string $qualifier,
        public readonly string $name,
        callable $handler,
        public readonly string $description = '',
        public readonly string $usage = '',
        public readonly Access $access = Access::Everyone,
        public readonly ?string $group = null,
        public readonly array $aliases = [],
        public readonly int $cooldown = 0,
        public readonly ?string $only = null,
        public readonly bool $sensitive = false,
        public readonly ?Slash $slash = null,
    ) {
        $this->handler = \Closure::fromCallable($handler);

        if ($qualifier === '' || preg_match('/^[a-z][a-z0-9-]{0,31}$/', $qualifier) !== 1) {
            throw new \LogicException("Action qualifier '{$qualifier}' is not a usable command name.");
        }

        if ($slash !== null && $only !== null && $only !== Surface::DISCORD) {
            throw new \LogicException("Action {$this->key()} is limited to {$only} but declares a slash command.");
        }
    }

    /**
     * How this action is keyed: qualifier and leaf name, which is also its
     * shortest unambiguous form.
     */
    public function key(): string
    {
        return strtolower($this->qualifier . ' ' . $this->name);
    }

    /**
     * Every part, in order — what a Discord slash command is built from.
     *
     * @return list<string>
     */
    public function path(): array
    {
        return $this->group === null
            ? [$this->qualifier, $this->name]
            : [$this->qualifier, $this->group, $this->name];
    }

    /** The full form, as Discord shows it: `twitch channel title`. */
    public function qualified(): string
    {
        return implode(' ', $this->path());
    }

    /** Whether this action is offered on `$surface` at all. */
    public function availableOn(Surface $surface): bool
    {
        return $this->only === null || $surface->is($this->only);
    }

    /**
     * Runs the handler.
     *
     * Permission is *not* checked here — the adapters do that, because each one
     * has to report a refusal in its own idiom, and because a platform's own
     * command client may want to make that decision itself.
     *
     * @return string|null|PromiseInterface
     */
    public function run(Context $context, Arguments $arguments): mixed
    {
        return ($this->handler)($context, $arguments);
    }

    /**
     * A one-line help entry, in the form the asking surface would accept.
     *
     * @param string $prefix What that surface puts in front of a command.
     * @param bool   $full   Whether to include the group — true for Discord,
     *                       false for a chat, which drops it.
     */
    public function help(string $prefix = '!', bool $full = false): string
    {
        $signature = $prefix . ($full ? $this->qualified() : $this->key())
            . ($this->usage !== '' ? ' ' . $this->usage : '');

        return $this->description === ''
            ? $signature
            : $signature . ' — ' . $this->description;
    }
}

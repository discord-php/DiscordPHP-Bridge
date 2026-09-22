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

use Bridge\Capability\ProvidesActions;

/**
 * The catalogue of every action the bot knows, and the single place every
 * adapter looks when it wires itself up.
 *
 * Actions are keyed by qualifier and leaf name — `twitch title` — which is the
 * shortest form any surface uses, so a collision here is a collision
 * everywhere. It throws rather than overwriting: two actions answering to
 * `!twitch title` would otherwise mean whichever registered last wins, with
 * nothing in the log to say so, and the loser's package would look broken from
 * the outside.
 *
 * That check is the whole reason qualifiers exist. It cannot fire between two
 * connectors, because each owns its own qualifier and {@see addFrom()} refuses
 * anything else — which means a connector can be installed without auditing
 * what every other connector happens to call things.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class ActionRegistry
{
    /** @var array<string, Action> keyed by {@see Action::key()} */
    private array $actions = [];

    /** @var array<string, string> qualified alias => action key */
    private array $aliases = [];

    public function add(Action $action): self
    {
        $key = $action->key();

        if ($this->has($key)) {
            throw new \LogicException("Duplicate command: {$key}");
        }

        $this->actions[$key] = $action;

        foreach ($action->aliases as $alias) {
            $alias = strtolower($action->qualifier . ' ' . $alias);

            if ($this->has($alias)) {
                throw new \LogicException("Duplicate command name or alias: {$alias}");
            }

            $this->aliases[$alias] = $key;
        }

        return $this;
    }

    /**
     * Adds every action a connector brings, holding it to its own qualifier.
     *
     * A connector that claimed another's qualifier could shadow its commands
     * from a separate package, which is exactly what none of this should be
     * able to do.
     */
    public function addFrom(ProvidesActions $provider, string $qualifier): self
    {
        foreach ($provider->actions() as $action) {
            if ($action->qualifier !== $qualifier) {
                throw new \LogicException(sprintf(
                    "The %s connector declared '%s', which belongs to %s.",
                    $qualifier,
                    $action->qualified(),
                    $action->qualifier,
                ));
            }

            $this->add($action);
        }

        return $this;
    }

    /** Adds every action from a provider, without the qualifier check. */
    public function addAll(ProvidesActions $provider): self
    {
        foreach ($provider->actions() as $action) {
            $this->add($action);
        }

        return $this;
    }

    /** Whether anything answers to this qualified name or alias. */
    public function has(string $key): bool
    {
        $key = self::normalise($key);

        return isset($this->actions[$key]) || isset($this->aliases[$key]);
    }

    public function get(string $key): ?Action
    {
        $key = self::normalise($key);

        return $this->actions[$key] ?? $this->actions[$this->aliases[$key] ?? ''] ?? null;
    }

    /**
     * Resolves however a chat spelled a command, in either of the two forms a
     * surface may use.
     *
     * A chat drops the group (`twitch title`); Discord keeps it (`twitch
     * channel title`). Both have to find the same action, and neither may find
     * one that was never qualified at all.
     *
     * @param  list<string> $words The message, already split.
     * @return array{0: ?Action, 1: list<string>} The action and what was left over.
     */
    public function resolve(array $words): array
    {
        // The three-word form first: `twitch channel title` must not be read as
        // `twitch channel` with "title" as an argument.
        foreach ([3, 2] as $take) {
            if (count($words) < $take) {
                continue;
            }

            $candidate = $take === 3
                ? $words[0] . ' ' . $words[2]
                : $words[0] . ' ' . $words[1];

            $action = $this->get($candidate);

            if ($action === null) {
                continue;
            }

            // A three-word match only counts when the middle word really is
            // that action's group.
            if ($take === 3 && strtolower((string) $action->group) !== strtolower($words[1])) {
                continue;
            }

            if ($take === 2 && $action->group !== null && count($words) > 2 && strtolower($words[1]) === strtolower($action->group)) {
                continue;
            }

            return [$action, array_slice($words, $take)];
        }

        return [null, $words];
    }

    /** @return array<string, Action> */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Every qualifier that has at least one action, in the order they were
     * first seen — which is the order a connector was added.
     *
     * @return list<string>
     */
    public function qualifiers(): array
    {
        $qualifiers = [];

        foreach ($this->actions as $action) {
            $qualifiers[$action->qualifier] = true;
        }

        return array_keys($qualifiers);
    }

    /**
     * Everything one connector brought.
     *
     * @return array<string, Action>
     */
    public function forQualifier(string $qualifier): array
    {
        return array_filter($this->actions, static fn (Action $a): bool => $a->qualifier === $qualifier);
    }

    /**
     * Everything offered on one surface.
     *
     * @return array<string, Action>
     */
    public function forSurface(Surface $surface): array
    {
        return array_filter($this->actions, static fn (Action $a): bool => $a->availableOn($surface));
    }

    /**
     * Actions grouped for a help listing, in registration order within each
     * group, and filtered to what `$access` may actually run — somebody asking
     * for help should not be shown a list of things they will be refused.
     *
     * Grouped by qualifier and then group, because with several connectors
     * installed "which platform is this for" is the first thing a reader needs.
     *
     * @return array<string, list<Action>>
     */
    public function grouped(Surface $surface, Access $access): array
    {
        $groups = [];

        foreach ($this->forSurface($surface) as $action) {
            if (! $access->satisfies($action->access)) {
                continue;
            }

            $heading = $action->group === null
                ? $action->qualifier
                : $action->qualifier . ' ' . $action->group;

            $groups[$heading][] = $action;
        }

        return $groups;
    }

    public function count(): int
    {
        return count($this->actions);
    }

    /** Collapses whitespace so `twitch  title` and `twitch title` are one key. */
    private static function normalise(string $key): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $key) ?? $key));
    }
}

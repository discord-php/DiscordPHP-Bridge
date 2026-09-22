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

use Bridge\Bot;

/**
 * Everything an action knows about the invocation it is servicing: who asked,
 * from where, what they are allowed to do, and — the interesting part — which
 * room on which network the action should act upon.
 *
 * That last one is the whole reason this object exists. In a platform's own
 * chat the target is obvious: the room the command was typed in. On Discord
 * there is no such thing, so the target is whatever that Discord channel is
 * bridged to, which means one command does the same thing in both places
 * without either handler knowing how the other one resolved it.
 *
 * With more than one connector installed that resolution needs a connector to
 * resolve *against*, and the command's own qualifier supplies it: `twitch
 * title` acts on the Twitch room this channel is bridged to, even when the same
 * channel is also bridged to a Telegram group.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Context
{
    /**
     * @param Surface $surface     The chat this was invoked from.
     * @param Access  $access      What the invoker may do, on the one ladder.
     * @param string  $invokerName Display name, for addressing a reply.
     * @param string  $invokerId   Platform user id.
     * @param ?string $connector   Which connector the action acts on.
     * @param ?string $target      The room, as {@see \Bridge\Links} stores it.
     * @param ?string $targetId    The platform's internal id, when it differs from the stored form.
     * @param bool    $isPublic    Whether the reply lands somewhere many people read.
     * @param ?object $message     The originating message, for an adapter that needs it.
     */
    public function __construct(
        public readonly Bot $bot,
        public readonly Surface $surface,
        public readonly Access $access,
        public readonly string $invokerName,
        public readonly string $invokerId,
        public readonly ?string $connector = null,
        public readonly ?string $target = null,
        public readonly ?string $targetId = null,
        public readonly bool $isPublic = true,
        public readonly ?object $message = null,
    ) {
    }

    /** A copy pointed at a different room. */
    public function withTarget(?string $target, ?string $targetId = null): self
    {
        return new self(
            $this->bot,
            $this->surface,
            $this->access,
            $this->invokerName,
            $this->invokerId,
            $this->connector,
            $target,
            $targetId,
            $this->isPublic,
            $this->message,
        );
    }

    /** A copy acting on a different connector. */
    public function withConnector(?string $connector): self
    {
        return new self(
            $this->bot,
            $this->surface,
            $this->access,
            $this->invokerName,
            $this->invokerId,
            $connector,
            $this->target,
            $this->targetId,
            $this->isPublic,
            $this->message,
        );
    }

    /**
     * The id to act on — the platform's own where it differs, otherwise the
     * stored form — or a thrown explanation.
     *
     * Actions that change something call this rather than testing for null
     * themselves, so "this channel isn't bridged yet" is worded once instead of
     * thirty times.
     *
     * @throws ActionError
     */
    public function requireTarget(): string
    {
        $id = $this->targetId ?? $this->target;

        if ($id === null || $id === '') {
            $connector = $this->connector ?? 'that network';

            throw new ActionError($this->surface->isDiscord()
                ? sprintf(
                    'this channel is not linked to %s yet — run `/%s link` first, or name one explicitly.',
                    $connector,
                    $connector,
                )
                : sprintf('could not work out which %s room this applies to.', $connector));
        }

        return $id;
    }

    /**
     * The room as it is stored, for anything that needs the name rather than
     * an internal id.
     *
     * @throws ActionError
     */
    public function requireRoom(): string
    {
        $target = $this->target;

        if ($target === null || $target === '') {
            $this->requireTarget();
        }

        return (string) $this->target;
    }

    /** Whether the invoker is the bot operator. */
    public function isOwner(): bool
    {
        return $this->access === Access::Operator;
    }

    /**
     * Resolves a rung on the one permission ladder.
     *
     * Static and free of any platform's part types so the ladder can be tested
     * directly; an adapter passes the booleans it reads off whatever its own
     * network calls these things.
     */
    public static function ladder(bool $isOwner, bool $isOwnerOfRoom, bool $isModerator): Access
    {
        return match (true) {
            $isOwner => Access::Operator,
            $isOwnerOfRoom => Access::Administrator,
            $isModerator => Access::Moderator,
            default => Access::Everyone,
        };
    }

    /**
     * Resolves a Discord permission rung.
     *
     * Guild owners and Administrators are treated as the top rung below the bot
     * operator, because on Discord that is the closest equivalent: they are the
     * people whose server it is. Manage Messages maps to moderator.
     */
    public static function discordAccess(bool $isGuildOwner, bool $isAdmin, bool $isModerator, bool $isOwner): Access
    {
        return self::ladder($isOwner, $isGuildOwner || $isAdmin, $isModerator);
    }
}

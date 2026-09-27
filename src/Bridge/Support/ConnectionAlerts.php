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

namespace Bridge\Support;

use Bridge\Bot;
use Bridge\Builders\PanelBuilder;
use Bridge\Capability\Reconnects;
use Bridge\Command\Access;
use Bridge\Connector;
use Bridge\Helpers\ComponentRouter;
use Discord\Builders\Components\Button;
use Discord\Builders\MessageBuilder;
use React\Promise\PromiseInterface;

/**
 * Tells the bot's owner when a connector's connection is down and is not
 * coming back by itself, with a button to try again.
 *
 * A bridge that loses its network and only says so in a logfile stays down
 * until somebody happens to look. The connector does the retrying; this is for
 * when retrying has not worked. There is one DM per outage, edited rather than
 * followed by another when the button is pressed and when the connection
 * returns.
 *
 * What went wrong stays in the log. An exception from a network library can
 * quote a request, and a DM is not the place to find out whether this one did.
 *
 * @author Valithor Obsidion <valithor@discordphp.org>
 */
final class ConnectionAlerts
{
    /** The button's action, as a {@see ComponentRouter} id. */
    public const RECONNECT = 'reconnect';

    /** @var array<string, object> The DM about each connector that is down, by name: anything with edit(). */
    private array $alerts = [];

    /** @var array<string, true> Connectors whose DM is on its way. */
    private array $sending = [];

    /** @var \Closure(MessageBuilder): PromiseInterface */
    private readonly \Closure $send;

    /**
     * @param (\Closure(MessageBuilder): PromiseInterface)|null $send How a DM reaches the owner, resolving to
     *                                                         the message, or to null when no owner is
     *                                                         configured. The bot's own by default.
     */
    public function __construct(private readonly Bot $bot, ?\Closure $send = null)
    {
        $this->send = $send ?? static fn (MessageBuilder $message): PromiseInterface => $bot->messageOwner($message);
    }

    /**
     * Routes the button. Its id carries the connector's name, so a DM from
     * before a restart still works.
     */
    public function register(): void
    {
        $this->bot->components()->on(
            self::RECONNECT,
            fn (object $interaction, array $args): PromiseInterface => $this->pressed($interaction, (string) ($args[0] ?? '')),
        );
    }

    /**
     * Says that a connector cannot get its connection back. Once per outage: a
     * second report while the first DM stands changes nothing.
     *
     * @param string   $detail Markdown for the DM: how long it has tried, and
     *                         what it does next. Never the error itself; see
     *                         the class comment.
     * @param int|null $since  When the connection went, as a Unix time.
     */
    public function failed(Connector $connector, string $detail = '', ?int $since = null): void
    {
        $name = $connector->name();

        if (isset($this->alerts[$name]) || isset($this->sending[$name])) {
            return;
        }

        $this->sending[$name] = true;

        ($this->send)(self::downPanel($connector, $detail, $since))->then(
            function (?object $message) use ($name): void {
                unset($this->sending[$name]);

                if ($message !== null) {
                    $this->alerts[$name] = $message;
                }
            },
            function (\Throwable $e) use ($name): void {
                unset($this->sending[$name]);
                $this->bot->getLogger()->error(sprintf('[bridge] could not DM the owner about %s: %s', $name, $e->getMessage()));
            },
        );
    }

    /** Says the connection is back, by editing the DM, when there was one. */
    public function restored(Connector $connector): void
    {
        $name = $connector->name();
        $message = $this->alerts[$name] ?? null;
        unset($this->alerts[$name]);

        $message?->edit(self::backPanel($connector, false))->then(
            null,
            fn (\Throwable $e) => $this->bot->getLogger()->warning(sprintf('[bridge] could not update the DM about %s: %s', $name, $e->getMessage())),
        );
    }

    /** Whether the owner has been told a connector is down, and not yet that it is back. */
    public function isAlerting(string $connector): bool
    {
        return isset($this->alerts[$connector]);
    }

    /**
     * The button. It is answered at once, because an interaction left for
     * three seconds fails, and the attempt's outcome then replaces that answer.
     */
    public function pressed(object $interaction, string $name): PromiseInterface
    {
        $connector = $this->bot->connector($name);
        $access = Permissions::accessForInteraction($interaction, $this->bot->getConfig()->discordOwnerId);

        if (! $access->satisfies(Access::Operator)) {
            return $interaction->respondWithMessage(
                PanelBuilder::error(sprintf('That is limited to %s.', Access::Operator->label())),
                true,
            );
        }

        if (! $connector instanceof Reconnects) {
            return $interaction->updateMessage(PanelBuilder::error(sprintf(
                '%s cannot be reconnected from here.',
                $connector?->label() ?? 'That connector',
            )));
        }

        // The DM is this interaction's to update now; restored() must not edit
        // it underneath. It goes back if the attempt fails.
        $alert = $this->alerts[$name] ?? $interaction->message ?? null;
        unset($this->alerts[$name]);

        return $interaction->updateMessage(
            PanelBuilder::notice(sprintf('🔄 Reconnecting to %s…', $connector->label())),
        )->then(fn () => $connector->reconnect()->then(
            fn () => $interaction->updateOriginalResponse(self::backPanel($connector, true)),
            function (\Throwable $e) use ($interaction, $connector, $name, $alert): PromiseInterface {
                $this->bot->getLogger()->warning(sprintf('[bridge] reconnecting %s by hand failed: %s', $name, $e->getMessage()));

                // Still down, and still retrying by itself: when that works, this DM says so.
                if ($alert !== null) {
                    $this->alerts[$name] = $alert;
                }

                return $interaction->updateOriginalResponse(self::downPanel($connector, 'That attempt failed too.'));
            },
        ));
    }

    private static function downPanel(Connector $connector, string $detail, ?int $since = null): MessageBuilder
    {
        $text = sprintf(
            '⚠️ **%s is disconnected**%s, and its bridges are not relaying.',
            $connector->label(),
            $since !== null ? sprintf(' since <t:%d:t>', $since) : '',
        );

        if ($detail !== '') {
            $text .= "\n" . $detail;
        }

        return PanelBuilder::warning($text . "\nThe reason is in the bot's log.")->addActions(
            Button::primary(ComponentRouter::id(self::RECONNECT, $connector->name()))->setLabel('Reconnect now'),
        );
    }

    private static function backPanel(Connector $connector, bool $byHand): MessageBuilder
    {
        return PanelBuilder::success(sprintf(
            '✅ **%s is back**, %s <t:%d:R>.',
            $connector->label(),
            $byHand ? 'reconnected' : 'reconnected by itself',
            time(),
        ));
    }
}

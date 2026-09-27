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

namespace Bridge\Tests\Doubles;

use Bridge\Bot;
use Bridge\Capability\Reconnects;
use Bridge\Command\Surface;
use Bridge\Connector;
use Bridge\Links;
use Bridge\Message\Outgoing;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A connector whose connection can be tried again, and says how that went:
 * {@see $reconnectFails} decides whether the next {@see reconnect()} works.
 */
final class ReconnectingConnector implements Connector, Reconnects
{
    public int $reconnects = 0;

    public bool $reconnectFails = false;

    public function __construct(private readonly string $name = 'network', private readonly string $label = 'Network')
    {
    }

    public function reconnect(): PromiseInterface
    {
        ++$this->reconnects;

        return $this->reconnectFails ? reject(new \RuntimeException('still down: GET https://secret.example/token=abc')) : resolve(true);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function surface(): Surface
    {
        return new Surface($this->name, $this->label, 500);
    }

    public function boot(Bot $bot): void
    {
    }

    public function start(): PromiseInterface
    {
        return resolve(true);
    }

    public function stop(): void
    {
    }

    public function sync(Links $links): array
    {
        return ['join' => [], 'part' => []];
    }

    public function send(string $target, string $text, array $options = []): PromiseInterface
    {
        return resolve(null);
    }

    public function relay(string $target, Outgoing $message): PromiseInterface
    {
        return resolve(null);
    }

    public function onIncoming(callable $handler): void
    {
    }

    public function resolve(string $target): PromiseInterface
    {
        return resolve(null);
    }

    public function normalise(string $input): ?string
    {
        return $input;
    }

    public function joined(): array
    {
        return [];
    }

    public function queued(): int
    {
        return 0;
    }
}

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
use Bridge\Capability\ProvidesActions;
use Bridge\Command\Action;
use Bridge\Command\Surface;
use Bridge\Connector;
use Bridge\Links;
use Bridge\Message\Incoming;
use Bridge\Message\Outgoing;
use Bridge\Room;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * A connector with no network behind it.
 *
 * Everything the core does with a connector — building the command tree,
 * routing, the startup check — is supposed to work without knowing which
 * network it is talking to. This is what proves it: if the core needs anything
 * this cannot provide, the abstraction has a hole in it.
 */
final class FakeConnector implements Connector, ProvidesActions
{
    /** @var list<array{target: string, text: string}> */
    public array $sent = [];

    /** @var list<array{target: string, message: Outgoing}> */
    public array $relayed = [];

    /** @var list<string> */
    public array $joined = [];

    public bool $started = false;

    public bool $stopped = false;

    /** Whether {@see start()} should reject, for testing a network that will not come up. */
    public bool $failToStart = false;

    /** @var list<callable(Incoming): void> */
    private array $handlers = [];

    /**
     * @param list<string>        $rooms   Which room names {@see resolve()} will admit to knowing.
     * @param list<Action>        $actions Commands this connector brings.
     */
    public function __construct(
        private readonly string $name = 'fake',
        private readonly string $label = 'Fake',
        private readonly array $rooms = ['somewhere'],
        private readonly array $actions = [],
    ) {
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
        return new Surface($this->name, $this->label, 500, markdown: false, lines: false, prefix: '!');
    }

    public function boot(Bot $bot): void
    {
    }

    public function start(): PromiseInterface
    {
        if ($this->failToStart) {
            return reject(new \RuntimeException('refused to start'));
        }

        $this->started = true;

        return resolve(true);
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function sync(Links $links): array
    {
        $want = $links->targets();
        $diff = [
            'join' => array_values(array_diff($want, $this->joined)),
            'part' => array_values(array_diff($this->joined, $want)),
        ];

        $this->joined = $want;

        return $diff;
    }

    public function send(string $target, string $text, array $options = []): PromiseInterface
    {
        $this->sent[] = ['target' => $target, 'text' => $text];

        return resolve('sent-' . count($this->sent));
    }

    public function relay(string $target, Outgoing $message): PromiseInterface
    {
        $this->relayed[] = ['target' => $target, 'message' => $message];

        return resolve('relayed-' . count($this->relayed));
    }

    public function onIncoming(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** Pretends a message arrived, for testing the inbound half. */
    public function receive(Incoming $incoming): void
    {
        foreach ($this->handlers as $handler) {
            $handler($incoming);
        }
    }

    public function resolve(string $target): PromiseInterface
    {
        return resolve(in_array($target, $this->rooms, true)
            ? new Room($target, ucfirst($target), 'https://example.test/' . $target)
            : null);
    }

    public function normalise(string $input): ?string
    {
        $value = strtolower(trim($input, " \t#@"));

        return preg_match('/^[a-z0-9_-]{1,32}$/', $value) === 1 ? $value : null;
    }

    public function joined(): array
    {
        return $this->joined;
    }

    public function queued(): int
    {
        return 0;
    }

    /** @return list<Action> */
    public function actions(): array
    {
        return $this->actions;
    }
}

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

namespace Bridge;

/**
 * The process environment, plus a `.env` file, read once and shared.
 *
 * Every connector needs settings of its own, and each one reading the file
 * itself would mean parsing it several times and — worse — disagreeing about
 * precedence. So the app loads this once and hands it to whatever needs it.
 *
 * The process environment wins over the file, so a container can override a
 * setting without the image being rebuilt or the file edited.
 *
 * Secrets only ever come from here, never from the JSON store — that is runtime
 * state a server admin edits through chat, and it should be safe to read, back
 * up, or paste into an issue.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Environment
{
    /** @param array<string, string> $file */
    private function __construct(
        private readonly array $file,
        public readonly string $path,
    ) {
    }

    /** Reads `$path` if it is there, and falls back to the process environment. */
    public static function load(string $path): self
    {
        $file = [];

        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);

                if ($trimmed === '' || $trimmed[0] === '#' || ! str_contains($trimmed, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $trimmed, 2);
                $file[trim($key)] = trim($value, " \t\"'");
            }
        }

        return new self($file, $path);
    }

    /** For tests, and for anything that already has its settings in hand. */
    public static function fromArray(array $values, string $path = ''): self
    {
        return new self(array_map(strval(...), $values), $path);
    }

    /** A setting, or `null` when it is absent or empty. */
    public function get(string $key): ?string
    {
        $value = getenv($key);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = $this->file[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A setting, or `$default`. */
    public function or(string $key, string $default): string
    {
        return $this->get($key) ?? $default;
    }

    /**
     * A setting, or a thrown explanation naming what is missing.
     *
     * @throws \RuntimeException
     */
    public function require(string $key): string
    {
        return $this->get($key)
            ?? throw new \RuntimeException("Missing required setting {$key} — see env.example.");
    }

    /** Whether every one of these is set, for deciding whether to install a connector at all. */
    public function has(string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->get($key) === null) {
                return false;
            }
        }

        return true;
    }
}

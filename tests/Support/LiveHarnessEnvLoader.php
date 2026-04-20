<?php

declare(strict_types=1);

namespace Apntalk\EslReact\Tests\Support;

final class LiveHarnessEnvLoader
{
    private const PREFIX = 'ESL_REACT_LIVE_';

    /** @var list<string> */
    private const FILES = [
        '.env.testing.local',
        '.env.live.local',
    ];

    public static function load(string $repoRoot): void
    {
        /** @var array<string, string> $values */
        $values = [];

        foreach (self::FILES as $file) {
            $path = rtrim($repoRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            foreach (self::parseFile($path) as $key => $value) {
                if (!str_starts_with($key, self::PREFIX)) {
                    continue;
                }

                // Later supported local files are more specific and may override
                // earlier local files, but never real process environment.
                $values[$key] = $value;
            }
        }

        foreach ($values as $key => $value) {
            if (self::hasProcessValue($key)) {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function parseFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }

            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
                continue;
            }

            $values[$key] = self::normalizeValue(substr($line, $separator + 1));
        }

        return $values;
    }

    private static function normalizeValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $unquoted = substr($value, 1, -1);

            return $quote === '"'
                ? strtr($unquoted, ['\\n' => "\n", '\\"' => '"', '\\\\' => '\\'])
                : $unquoted;
        }

        $comment = strpos($value, ' #');
        if ($comment !== false) {
            $value = substr($value, 0, $comment);
        }

        return trim($value);
    }

    private static function hasProcessValue(string $key): bool
    {
        return getenv($key) !== false
            || array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER);
    }
}

<?php
declare(strict_types=1);

namespace NutriPlan;

/** Reads settings from real environment variables first, then backend/.env. */
final class Config
{
    private static ?array $env = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$env === null) {
            self::$env = [];
            $file = dirname(__DIR__) . '/.env';
            if (is_file($file)) {
                foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    self::$env[trim($k)] = trim($v, " \t\"'");
                }
            }
        }
        $v = getenv($key);
        if ($v !== false && $v !== '') {
            return $v;
        }
        return self::$env[$key] ?? $default;
    }
}

<?php
/**
 * GNCP Environment Loader
 * Lightweight, zero-dependency environment configuration reader.
 * Loads variables from .env into $_ENV, $_SERVER, and getenv().
 */

if (!function_exists('loadEnv')) {
    function loadEnv(?string $path = null): void {
        static $loaded = false;
        if ($loaded && $path === null) {
            return;
        }

        if ($path === null) {
            $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '.env';
        }

        if (!file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, ';')) {
                continue;
            }

            $pos = strpos($trimmed, '=');
            if ($pos === false) {
                continue;
            }

            $name  = trim(substr($trimmed, 0, $pos));
            $value = trim(substr($trimmed, $pos + 1));

            // Strip enclosing quotes if present
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            // Populate environments if not already overridden by system environment
            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
            if (!array_key_exists($name, $_SERVER)) {
                $_SERVER[$name] = $value;
            }
            if (getenv($name) === false) {
                putenv("$name=$value");
            }
        }

        $loaded = true;
    }
}

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($val === false || $val === null) {
            return $default;
        }

        return match (strtolower((string)$val)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)'   => null,
            default            => $val,
        };
    }
}

// Auto-load .env on include
loadEnv();

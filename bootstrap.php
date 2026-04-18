<?php

require_once __DIR__ . '/lib/DbCompat.php';

function ntp_env(string $key, ?string $default = null): ?string
{
    static $loaded = false;
    static $envData = [];

    if (!$loaded) {
        $loaded = true;
        $envPath = __DIR__ . '/.env';
        if (is_file($envPath)) {
            $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#') {
                        continue;
                    }
                    $eq = strpos($line, '=');
                    if ($eq === false) {
                        continue;
                    }
                    $envKey = trim(substr($line, 0, $eq));
                    $envValue = trim(substr($line, $eq + 1));
                    $envData[$envKey] = trim($envValue, "\"'");
                }
            }
        }
    }

    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    if (isset($envData[$key]) && trim((string) $envData[$key]) !== '') {
        return trim((string) $envData[$key]);
    }

    return $default;
}

function ntp_db_connect(): DbCompatConnection
{
    $databaseUrl = ntp_env('DATABASE_URL');

    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        if ($parts !== false) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $dialect = in_array($scheme, ['postgres', 'postgresql', 'pgsql'], true) ? 'pgsql' : 'mysql';
            return DbCompatConnection::fromConfig([
                'dialect' => $dialect,
                'host' => $parts['host'] ?? '127.0.0.1',
                'port' => $parts['port'] ?? ($dialect === 'pgsql' ? 5432 : 3306),
                'dbname' => isset($parts['path']) ? ltrim($parts['path'], '/') : '',
                'user' => $parts['user'] ?? '',
                'password' => $parts['pass'] ?? '',
                'sslmode' => ntp_env('DB_SSLMODE', 'require'),
            ]);
        }
    }

    $dialect = strtolower((string) ntp_env('DB_CONNECTION', 'mysql'));
    if ($dialect === 'postgres' || $dialect === 'postgresql') {
        $dialect = 'pgsql';
    }

    return DbCompatConnection::fromConfig([
        'dialect' => $dialect,
        'host' => ntp_env('DB_HOST', '127.0.0.1'),
        'port' => ntp_env('DB_PORT', $dialect === 'pgsql' ? '5432' : '3306'),
        'dbname' => ntp_env('DB_DATABASE', 'ntp_lampung'),
        'user' => ntp_env('DB_USERNAME', 'root'),
        'password' => ntp_env('DB_PASSWORD', ''),
        'sslmode' => ntp_env('DB_SSLMODE', 'require'),
    ]);
}

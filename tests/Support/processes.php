<?php

/*
| Helpers for tests/Concurrency: run a script from tests/Support in a second PHP process
| with its own database connection to the same PostgreSQL test database.
*/

/**
 * @param  list<string>  $args
 * @return array{handle: resource, pipes: array<int, resource>}
 */
function startPhpProcess(string $script, array $args): array
{
    $db = config('database.connections.pgsql');
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => (string) $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'],
        'DB_PASSWORD' => (string) $db['password'],
    ]);

    $handle = proc_open(
        [PHP_BINARY, base_path('tests/Support/'.$script), ...$args],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        $env,
    );

    if (! is_resource($handle)) {
        throw new RuntimeException("Could not start {$script}.");
    }

    return ['handle' => $handle, 'pipes' => $pipes];
}

/**
 * @param  array{handle: resource, pipes: array<int, resource>}  $process
 * @return array<string, mixed>
 */
function finishProcess(array $process): array
{
    $out = stream_get_contents($process['pipes'][1]);
    $err = stream_get_contents($process['pipes'][2]);
    fclose($process['pipes'][1]);
    fclose($process['pipes'][2]);
    proc_close($process['handle']);

    $decoded = json_decode((string) $out, true);

    if (! is_array($decoded)) {
        throw new RuntimeException("Second process said: {$out}\n{$err}");
    }

    return $decoded;
}

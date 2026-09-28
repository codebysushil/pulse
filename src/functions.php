<?php
declare(strict_types=1);

namespace Pulse;

/**
 * Start a task in its own Fiber. Runs eagerly until its first await.
 * Returns a Promise for the task's result.
 */
function async(callable $fn, mixed ...$args): Promise
{
    $fiber = new \Fiber($fn);
    $promise = new Promise;

    $step = null;
    $step = function (callable $resume) use ($fiber, $promise, &$step): void {
        try {
            $awaited = $resume(); // Fiber yields the Promise it's waiting on
        } catch (\Throwable $e) {
            $promise->reject($e);
            return;
        }

        if ($fiber->isTerminated()) {
            $promise->resolve($fiber->getReturn());
            return;
        }

        $awaited->then(
            fn($v) => $step(fn() => $fiber->resume($v)),
            fn($e) => $step(fn() => $fiber->throw($e)),
        );
    };

    $step(fn() => $fiber->start(...$args));
    return $promise;
}

/**
 * Suspend the current task until $value settles.
 * Outside a task (top level) it drives the event loop instead.
 */
function await(mixed $value): mixed
{
    if (!$value instanceof Promise) return $value;

    if (\Fiber::getCurrent() === null) {
        return Loop::get()->runUntil($value);
    }
    return \Fiber::suspend($value);
}

/** Non-blocking sleep. */
function delay(float $seconds): Promise
{
    $p = new Promise;
    Loop::get()->delay($seconds, fn() => $p->resolve(null));
    return $p;
}

/** Run several callables/promises concurrently; returns array of results. */
function gather(array $tasks): Promise
{
    $promises = array_map(
        fn($t) => $t instanceof Promise ? $t : (is_callable($t) ? async($t) : Promise::resolved($t)),
        $tasks
    );
    return Promise::all($promises);
}

/** Reject with TimeoutException if $promise takes longer than $seconds. */
function timeout(Promise $promise, float $seconds): Promise
{
    $timer = new Promise;
    $id = Loop::get()->delay($seconds, fn() => $timer->reject(new \RuntimeException("Timed out after {$seconds}s")));
    return Promise::race([$promise, $timer])->finally(fn() => Loop::get()->cancelTimer($id));
}

/** Entry point: run an async main function to completion. */
function run(callable $main): mixed
{
    return await(async($main));
}

/** Resolves when $stream is readable. */
function readable($stream): Promise
{
    $p = new Promise;
    Loop::get()->onReadable($stream, fn() => $p->resolve(null));
    return $p;
}

/** Resolves when $stream is writable. */
function writable($stream): Promise
{
    $p = new Promise;
    Loop::get()->onWritable($stream, fn() => $p->resolve(null));
    return $p;
}

/**
 * Minimal non-blocking HTTP GET (http:// only).
 * Note: DNS lookup is still blocking; TLS non-blocking is omitted for simplicity.
 */
function fetch(string $url): Promise
{
    return async(function () use ($url) {
        $parts = parse_url($url);
        $host = $parts['host'];
        $port = $parts['port'] ?? 80;
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $socket = stream_socket_client(
            "tcp://" . gethostbyname($host) . ":$port",
            $errno, $errstr, 0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        );
        if (!$socket) throw new \RuntimeException("Connect failed: $errstr");
        stream_set_blocking($socket, false);

        try {
            await(writable($socket)); // connected
            $request = "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n";
            fwrite($socket, $request);

            $response = '';
            while (!feof($socket)) {
                await(readable($socket));
                $chunk = fread($socket, 8192);
                if ($chunk === false) break;
                $response .= $chunk;
            }
        } finally {
            fclose($socket);
        }

        [$headers, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
        return ['headers' => $headers, 'body' => $body];
    });
}

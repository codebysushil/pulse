<?php
declare(strict_types=1);

namespace Pulse;

final class Promise
{
    private const PENDING = 0, FULFILLED = 1, REJECTED = 2;

    private int $state = self::PENDING;
    private bool $locked = false; // resolved/rejected, maybe waiting on another promise
    private mixed $result = null;
    /** @var array<array{0:?callable,1:?callable,2:Promise}> */
    private array $handlers = [];

    public function resolve(mixed $value = null): void
    {
        if ($this->locked) return;
        $this->locked = true;

        if ($value instanceof self) {
            $value->then(
                fn($v) => $this->settle(self::FULFILLED, $v),
                fn($e) => $this->settle(self::REJECTED, $e),
            );
            return;
        }
        $this->settle(self::FULFILLED, $value);
    }

    public function reject(\Throwable $e): void
    {
        if ($this->locked) return;
        $this->locked = true;
        $this->settle(self::REJECTED, $e);
    }

    private function settle(int $state, mixed $result): void
    {
        if ($this->state !== self::PENDING) return;
        $this->state = $state;
        $this->result = $result;
        $handlers = $this->handlers;
        $this->handlers = [];
        foreach ($handlers as $h) $this->schedule($h);
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): self
    {
        $next = new self;
        $h = [$onFulfilled, $onRejected, $next];
        if ($this->state === self::PENDING) {
            $this->handlers[] = $h;
        } else {
            $this->schedule($h);
        }
        return $next;
    }

    public function catch(callable $onRejected): self
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $fn): self
    {
        return $this->then(
            function ($v) use ($fn) { $fn(); return $v; },
            function ($e) use ($fn) { $fn(); throw $e; },
        );
    }

    private function schedule(array $h): void
    {
        [$onF, $onR, $next] = $h;
        $state = $this->state;
        $result = $this->result;

        Loop::get()->defer(function () use ($state, $result, $onF, $onR, $next) {
            try {
                if ($state === self::FULFILLED) {
                    $onF ? $next->resolve($onF($result)) : $next->resolve($result);
                } else {
                    if ($onR) $next->resolve($onR($result));
                    else $next->reject($result);
                }
            } catch (\Throwable $e) {
                $next->reject($e);
            }
        });
    }

    public function isPending(): bool  { return $this->state === self::PENDING; }
    public function isRejected(): bool { return $this->state === self::REJECTED; }
    public function result(): mixed    { return $this->result; }

    // ---- Static helpers ----

    public static function resolved(mixed $v = null): self
    {
        $p = new self; $p->resolve($v); return $p;
    }

    public static function rejected(\Throwable $e): self
    {
        $p = new self; $p->reject($e); return $p;
    }

    /** Resolves with all results (keys preserved); rejects on first failure. */
    public static function all(array $promises): self
    {
        $out = new self;
        if (!$promises) { $out->resolve([]); return $out; }

        $results = [];
        $remaining = count($promises);
        foreach ($promises as $key => $p) {
            $p = $p instanceof self ? $p : self::resolved($p);
            $p->then(
                function ($v) use (&$results, &$remaining, $key, $out, $promises) {
                    $results[$key] = $v;
                    if (--$remaining === 0) {
                        $ordered = [];
                        foreach (array_keys($promises) as $k) $ordered[$k] = $results[$k];
                        $out->resolve($ordered);
                    }
                },
                fn($e) => $out->reject($e),
            );
        }
        return $out;
    }

    /** Settles with the first promise to settle. */
    public static function race(array $promises): self
    {
        $out = new self;
        foreach ($promises as $p) {
            $p = $p instanceof self ? $p : self::resolved($p);
            $p->then(fn($v) => $out->resolve($v), fn($e) => $out->reject($e));
        }
        return $out;
    }
}

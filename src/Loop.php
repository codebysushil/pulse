<?php
declare(strict_types=1);

namespace Pulse;

final class Loop
{
    private static ?self $instance = null;

    /** @var \SplQueue<callable> */
    private \SplQueue $microtasks;
    /** @var array<int,array{0:float,1:callable}> */
    private array $timers = [];
    private array $readers = [];  // id => [stream, callback]
    private array $writers = [];
    private int $timerId = 0;

    private function __construct()
    {
        $this->microtasks = new \SplQueue;
    }

    public static function get(): self
    {
        return self::$instance ??= new self;
    }

    /** Run callback on the next microtask pass. */
    public function defer(callable $fn): void
    {
        $this->microtasks->enqueue($fn);
    }

    /** Run callback after $seconds. Returns timer id. */
    public function delay(float $seconds, callable $fn): int
    {
        $id = ++$this->timerId;
        $this->timers[$id] = [microtime(true) + $seconds, $fn];
        return $id;
    }

    public function cancelTimer(int $id): void
    {
        unset($this->timers[$id]);
    }

    /** One-shot: call $fn when $stream becomes readable. */
    public function onReadable($stream, callable $fn): void
    {
        $this->readers[(int)$stream] = [$stream, $fn];
    }

    /** One-shot: call $fn when $stream becomes writable. */
    public function onWritable($stream, callable $fn): void
    {
        $this->writers[(int)$stream] = [$stream, $fn];
    }

    private function hasWork(): bool
    {
        return !$this->microtasks->isEmpty()
            || $this->timers
            || $this->readers
            || $this->writers;
    }

    private function runMicrotasks(): void
    {
        while (!$this->microtasks->isEmpty()) {
            ($this->microtasks->dequeue())();
        }
    }

    /** A single loop iteration. */
    private function tick(): void
    {
        $this->runMicrotasks();

        // Due timers
        $now = microtime(true);
        foreach ($this->timers as $id => [$at, $fn]) {
            if ($at <= $now) {
                unset($this->timers[$id]);
                $fn();
            }
        }
        $this->runMicrotasks();

        if (!$this->microtasks->isEmpty()) return;

        // Time until next timer
        $timeout = null;
        if ($this->timers) {
            $next = min(array_column($this->timers, 0));
            $timeout = max(0.0, $next - microtime(true));
        }

        if (!$this->readers && !$this->writers) {
            if ($timeout !== null && $timeout > 0) usleep((int)($timeout * 1_000_000));
            return;
        }

        $r = array_column($this->readers, 0);
        $w = array_column($this->writers, 0);
        $e = null;

        $sec  = $timeout === null ? null : (int)floor($timeout);
        $usec = $timeout === null ? 0 : (int)(($timeout - $sec) * 1_000_000);

        if (@stream_select($r, $w, $e, $sec, $usec) === false) return;

        foreach ($r as $stream) {
            $id = (int)$stream;
            if (isset($this->readers[$id])) {
                [, $fn] = $this->readers[$id];
                unset($this->readers[$id]);
                $fn();
            }
        }
        foreach ($w as $stream) {
            $id = (int)$stream;
            if (isset($this->writers[$id])) {
                [, $fn] = $this->writers[$id];
                unset($this->writers[$id]);
                $fn();
            }
        }
        $this->runMicrotasks();
    }

    /** Drive the loop until $promise settles; return its value or throw. */
    public function runUntil(Promise $promise): mixed
    {
        while ($promise->isPending() && $this->hasWork()) {
            $this->tick();
        }
        if ($promise->isPending()) {
            throw new \LogicException('Deadlock: awaited promise can never resolve.');
        }
        if ($promise->isRejected()) throw $promise->result();
        return $promise->result();
    }
}

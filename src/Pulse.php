<?php

declare(strict_types=1);

namespace Pulse;

use Fiber;

final class Pulse
{
    private ?Fiber $fiber = null;

    public function __construct()
    {
    }

    public function start(callable $callback): mixed
    {
        $this->fiber = new Fiber($callback);

        return $this->fiber->start();
    }

    public function resume(mixed $value = null): mixed
    {
        if ($this->fiber === null || !$this->fiber->isSuspended()) {
            return null;
        }

        return $this->fiber->resume($value);
    }

    public function suspend(mixed $value = null): mixed
    {
        return Fiber::suspend($value);
    }
}

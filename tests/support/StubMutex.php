<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

/**
 * In-memory stand-in for Craft's mutex.
 *
 * Set $failToAcquire to emulate a lock that can't be taken, which is the branch
 * where the attempt counter fails safe rather than letting the attempt through.
 */
class StubMutex
{
    public bool $failToAcquire = false;
    public array $held = [];

    public function acquire(string $name, int $timeout = 0): bool
    {
        if ($this->failToAcquire) {
            return false;
        }

        $this->held[$name] = true;

        return true;
    }

    public function release(string $name): bool
    {
        unset($this->held[$name]);

        return true;
    }
}

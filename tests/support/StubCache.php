<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

/**
 * In-memory stand-in for Craft's cache. TTLs are recorded rather than enforced,
 * since the tests assert on what gets stored, not on wall-clock expiry.
 *
 * Set $null to emulate a null/dummy cache driver, where writes are dropped and
 * reads always miss. That's the configuration that silently disables the
 * attempt lockout, so the gate's behaviour under it is worth pinning down.
 */
class StubCache
{
    public array $data = [];
    public array $ttls = [];
    public bool $null = false;

    public function get(string $key): mixed
    {
        return $this->null ? false : ($this->data[$key] ?? false);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->null) {
            return true;
        }

        $this->data[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key], $this->ttls[$key]);

        return true;
    }
}

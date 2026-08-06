<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

/**
 * In-memory stand-in for Craft's session, enough for the gate's unlock tokens.
 */
class StubSession
{
    public array $data = [];
    public array $errors = [];

    /** Ids handed out by regenerateID(), oldest first. */
    public array $ids = ['session-1'];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * PHP moves $_SESSION to the new id, so the stub keeps its data too. Records
     * the change rather than dropping it, so tests can assert it happened.
     */
    public function regenerateID(bool $deleteOldSession = false): void
    {
        $this->ids[] = 'session-' . (count($this->ids) + 1);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function setError(string $error): void
    {
        $this->errors[] = $error;
    }
}

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

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
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

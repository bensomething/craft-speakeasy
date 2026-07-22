<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

/**
 * Stand-in for Craft's request, carrying the body params and client IP the
 * unlock action reads.
 */
class StubRequest
{
    public array $bodyParams = [];
    public ?string $userIp = '203.0.113.1';

    public function getBodyParam(string $name, mixed $default = null): mixed
    {
        return $this->bodyParams[$name] ?? $default;
    }

    public function getUserIP(): ?string
    {
        return $this->userIp;
    }
}

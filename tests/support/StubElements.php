<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

use craft\base\ElementInterface;

/**
 * Stand-in for Craft's elements service, backed by elements the test registers.
 */
class StubElements
{
    /** @var array<int, ElementInterface> */
    public array $elements = [];

    public function add(int $id, ElementInterface $element): void
    {
        $this->elements[$id] = $element;
    }

    public function getElementById(int $id): ?ElementInterface
    {
        return $this->elements[$id] ?? null;
    }
}

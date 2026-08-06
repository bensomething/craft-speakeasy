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

    /** Criteria the last lookup was constrained by. */
    public array $criteria = [];

    public function getElementById(int $id, ?string $elementType = null, array|int|string|null $siteId = null, array $criteria = []): ?ElementInterface
    {
        $this->criteria = $criteria;

        return $this->elements[$id] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

/**
 * Stand-in for Craft's URL manager. Null matched element is a route with no
 * element behind it, e.g. a plain template or a custom route.
 */
class StubUrlManager
{
    public mixed $matchedElement = null;

    public function getMatchedElement(): mixed
    {
        return $this->matchedElement;
    }
}

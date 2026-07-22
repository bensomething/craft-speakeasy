<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

use craft\elements\User;

/**
 * Stand-in for Craft's user component. Null identity is an anonymous visitor.
 */
class StubUser
{
    public ?User $identity = null;

    public function getIdentity(): ?User
    {
        return $this->identity;
    }
}

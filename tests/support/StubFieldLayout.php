<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

use craft\base\FieldInterface;
use craft\models\FieldLayout;

/**
 * A field layout with its custom fields set directly.
 *
 * Building a real layout goes through FieldLayoutTab, which resolves elements
 * via the fields service and so needs a database. The gate only ever asks a
 * layout for getCustomFields(), so overriding that is the whole surface it uses.
 */
class StubFieldLayout extends FieldLayout
{
    /** @param FieldInterface[] $fields */
    public function __construct(private array $fields = [])
    {
        parent::__construct();
    }

    public function getCustomFields(): array
    {
        return $this->fields;
    }
}

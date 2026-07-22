<?php

namespace bensomething\speakeasy\fields\conditions;

use bensomething\speakeasy\fields\PasswordValue;
use craft\base\conditions\BaseLightswitchConditionRule;
use craft\fields\conditions\FieldConditionRuleInterface;
use craft\fields\conditions\FieldConditionRuleTrait;

/**
 * Index/entry filter for the Speakeasy Password field. A lightswitch matching
 * elements that have a password set (on) or don't (off). The stored value is
 * encrypted, so this only ever tests presence via :notempty:/:empty:, never the
 * password itself.
 */
class HasPasswordConditionRule extends BaseLightswitchConditionRule implements FieldConditionRuleInterface
{
    use FieldConditionRuleTrait;

    protected function elementQueryParam(): mixed
    {
        return $this->value ? ':notempty:' : ':empty:';
    }

    protected function matchFieldValue($value): bool
    {
        $hasPassword = $value instanceof PasswordValue && !$value->isEmpty();
        return $this->matchValue($hasPassword);
    }
}

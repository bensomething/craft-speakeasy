<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

/**
 * Stand-in for Craft's i18n component. Returns the source message with any
 * placeholders filled, which is what an untranslated string resolves to anyway,
 * so tests can assert on the English text.
 */
class StubI18n
{
    public function translate(string $category, string $message, array $params = [], ?string $language = null): string
    {
        $placeholders = [];
        foreach ($params as $name => $value) {
            $placeholders['{' . $name . '}'] = $value;
        }

        return $placeholders === [] ? $message : strtr($message, $placeholders);
    }
}

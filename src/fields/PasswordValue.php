<?php

namespace bensomething\sesame\fields;

use Stringable;

/**
 * Wraps a decrypted Sesame password so it isn't accidentally exposed. In string
 * context — `{{ entry.field }}`, logs, element index columns — it renders a
 * fixed mask, never the real value. The plaintext is reachable only by passing a
 * RevealToken, which Sesame's own code holds but a Twig template can't produce —
 * so `{{ entry.field.revealPassword }}` and generated-field templates get the
 * mask, not the password.
 */
class PasswordValue implements Stringable
{
    public function __construct(private readonly string $password)
    {
    }

    /**
     * Unwrap the plaintext. Twig invokes accessors with no arguments, so a
     * template call falls through to the mask; only a caller holding a
     * RevealToken (i.e. Sesame itself) gets the real value.
     */
    public function revealPassword(?RevealToken $token = null): string
    {
        return $token instanceof RevealToken ? $this->password : (string) $this;
    }

    public function isEmpty(): bool
    {
        return $this->password === '';
    }

    public function __toString(): string
    {
        return $this->password === '' ? '' : '••••••••';
    }
}

<?php

namespace bensomething\speakeasy\fields;

use Closure;
use Stringable;

/**
 * Wraps a Speakeasy password so it isn't accidentally exposed. In string context —
 * `{{ entry.field }}`, logs, element index columns — it renders a fixed mask,
 * never the real value. The plaintext is reachable only by passing a RevealToken,
 * which Speakeasy's own code holds but a Twig template can't produce — so
 * `{{ entry.field.revealPassword }}` and generated-field templates get the mask.
 *
 * A value loaded from the database is held as ciphertext and decrypted lazily on
 * the first guarded revealPassword() call, so presence checks (`{% if entry.field %}`),
 * the mask, and index columns never decrypt.
 */
class PasswordValue implements Stringable
{
    private ?string $plain;
    private ?Closure $resolver;

    /**
     * Pass a plain string for a value entered in the editor, or a Closure that
     * returns the plaintext (e.g. decrypts stored ciphertext) to defer the work.
     */
    public function __construct(string|Closure $value)
    {
        if ($value instanceof Closure) {
            $this->plain = null;
            $this->resolver = $value;
        } else {
            $this->plain = $value;
            $this->resolver = null;
        }
    }

    /**
     * Unwrap the plaintext, resolving a deferred value on first use. Twig invokes
     * accessors with no arguments, so a template call falls through to the mask;
     * only a caller holding a RevealToken (i.e. Speakeasy itself) gets the real value.
     */
    public function revealPassword(?RevealToken $token = null): string
    {
        if (!$token instanceof RevealToken) {
            return (string) $this;
        }

        if ($this->plain === null && $this->resolver !== null) {
            $this->plain = ($this->resolver)();
            $this->resolver = null;
        }

        return $this->plain ?? '';
    }

    public function isEmpty(): bool
    {
        // Only ever constructed for a stored value, so an unresolved (lazy) value
        // is set; a resolved one reflects its actual plaintext.
        return $this->plain === '';
    }

    public function __toString(): string
    {
        return $this->isEmpty() ? '' : '••••••••';
    }
}

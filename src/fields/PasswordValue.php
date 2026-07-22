<?php

namespace bensomething\speakeasy\fields;

use Closure;
use Stringable;

/**
 * Wraps a Speakeasy password so it isn't accidentally exposed. In string context
 * (`{{ entry.field }}`, logs, element index columns) it renders a fixed mask,
 * never the real value. The plaintext is reachable only by passing a RevealToken,
 * which Speakeasy's own code holds but a Twig template can't produce, so
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
    private bool $undecryptable = false;

    /**
     * Pass a plain string for a value entered in the editor, or a Closure to defer
     * the work of decrypting a stored value. The Closure returns the resolved
     * string and whether it decrypted, as `[$value, $decrypted]`.
     *
     * @param string|Closure(): array{string, bool} $value
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
     * A stored value already known not to decrypt, wrapped without re-checking.
     * Used when an untouched undecryptable value is posted back by the editor,
     * so it can be written away again exactly as it was found.
     */
    public static function undecryptable(string $stored): self
    {
        $value = new self($stored);
        $value->undecryptable = true;

        return $value;
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

        $this->resolve();

        return $this->plain ?? '';
    }

    /**
     * Whether the stored value couldn't be decrypted, which means the security key
     * has changed since it was saved and the original password is unrecoverable.
     * The value is kept (so the element stays gated) but it's ciphertext, not a
     * password anyone set, and the editor needs to replace it.
     *
     * Resolving is required to know this, but no plaintext is handed out, so this
     * stays safe to call from the CP without a reveal token.
     */
    public function isUndecryptable(): bool
    {
        $this->resolve();

        return $this->undecryptable;
    }

    private function resolve(): void
    {
        if ($this->plain === null && $this->resolver !== null) {
            [$this->plain, $decrypted] = ($this->resolver)();
            $this->undecryptable = !$decrypted;
            $this->resolver = null;
        }
    }

    public function isEmpty(): bool
    {
        // Only ever constructed for a stored value, so an unresolved (lazy) value
        // is set. A resolved one reflects its actual plaintext.
        return $this->plain === '';
    }

    public function __toString(): string
    {
        return $this->isEmpty() ? '' : '••••••••';
    }
}

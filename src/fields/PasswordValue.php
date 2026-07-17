<?php

namespace bensomething\sesame\fields;

use Stringable;

/**
 * Wraps a decrypted Sesame password so it isn't accidentally exposed. In string
 * context — `{{ entry.field }}`, logs, element index columns — it renders a
 * fixed mask, never the real value. The password is reachable only via
 * revealPassword(), so a natural `{{ entry.field.password }}` resolves to
 * nothing rather than leaking.
 */
class PasswordValue implements Stringable
{
    public function __construct(private readonly string $password)
    {
    }

    public function revealPassword(): string
    {
        return $this->password;
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

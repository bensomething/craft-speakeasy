<?php

namespace bensomething\speakeasy\fields;

/**
 * Capability marker required to unwrap a PasswordValue's plaintext. Only Speakeasy's
 * own PHP passes one. Twig object templates can only invoke accessors with no
 * arguments, and can't construct this type, so the plaintext stays unreachable
 * from `{{ entry.field.revealPassword }}` and generated-field templates.
 */
final class RevealToken
{
}

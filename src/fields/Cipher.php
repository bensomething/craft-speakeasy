<?php

namespace bensomething\speakeasy\fields;

use Craft;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypts and decrypts stored passwords on Craft 5 and Craft 6.
 *
 * Craft 5 uses Craft's security component, keyed by the `securityKey` setting.
 * Craft 6 encrypts with Laravel's encrypter (`APP_KEY`), because the Yii2
 * adapter's legacy `encryptByKey()` still needs the deprecated `securityKey`,
 * which a fresh Craft 6 install doesn't have. Values saved on Craft 5 still
 * decrypt on Craft 6 through the legacy component, as long as the old key is
 * configured.
 *
 * @internal
 */
final class Cipher
{
    public static function encrypt(string $plain): string
    {
        if (self::isCraft6()) {
            return Crypt::encryptString($plain);
        }

        return Craft::$app->getSecurity()->encryptByKey($plain);
    }

    /**
     * Returns the plaintext, or `null` if the value can't be decrypted with the
     * configured key(s). Never throws: a missing or empty key makes Yii's
     * `hash_hkdf()` throw rather than return false, and the caller relies on a
     * failure keeping the element locked.
     */
    public static function decrypt(string $encrypted): ?string
    {
        if (self::isCraft6()) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                // Not encrypted by Laravel: probably saved on Craft 5.
            }
        }

        try {
            $decrypted = Craft::$app->getSecurity()->decryptByKey($encrypted);
        } catch (Throwable) {
            return null;
        }

        return $decrypted === false ? null : $decrypted;
    }

    private static function isCraft6(): bool
    {
        return version_compare(Craft::$app->getVersion(), '6.0.0-alpha', '>=')
            && class_exists(Crypt::class);
    }
}

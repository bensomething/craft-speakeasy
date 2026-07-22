<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\fields\RevealToken;
use bensomething\speakeasy\tests\support\CraftStub;
use craft\models\GqlSchema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The parts of the field that go through Craft's security component: encrypting
 * on the way to the database and decrypting on the way back, plus the paths that
 * must never surface the plaintext.
 */
#[CoversClass(PasswordField::class)]
final class PasswordFieldCryptoTest extends TestCase
{
    private PasswordField $field;

    protected function setUp(): void
    {
        CraftStub::install();
        $this->field = new PasswordField(['handle' => 'pw']);
    }

    protected function tearDown(): void
    {
        CraftStub::uninstall();
    }

    public function testAPasswordSurvivesARoundTripThroughStorage(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);
        $value = $this->field->normalizeValue($stored, null);

        self::assertSame('hunter2', $value->revealPassword(new RevealToken()));
    }

    public function testStoredValuesAreNeitherPlaintextNorReversibleWithoutTheKey(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        self::assertStringNotContainsString('hunter2', $stored);
        self::assertStringNotContainsString('hunter2', (string) base64_decode($stored, true));
    }

    public function testTheSamePasswordEncryptsDifferentlyEachTime(): void
    {
        $a = $this->field->serializeValue(new PasswordValue('hunter2'), null);
        $b = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        self::assertNotSame($a, $b, 'Ciphertext is deterministic, so equal passwords are identifiable in the database');
    }

    public function testUnicodeAndLongPasswordsRoundTrip(): void
    {
        foreach (['pässwörd ✨', str_repeat('a', 1000), '  spaces  ', "tab\tand\nnewline"] as $password) {
            $stored = $this->field->serializeValue(new PasswordValue($password), null);
            $value = $this->field->normalizeValue($stored, null);

            self::assertSame($password, $value->revealPassword(new RevealToken()));
        }
    }

    // --- fail-closed on key loss -------------------------------------------

    /**
     * If the security key is rotated or lost, the stored value can't be decrypted.
     * It must stay non-empty so the element stays gated, and must never decrypt to
     * something a visitor could guess or to an empty string that reads as "no
     * password set".
     */
    public function testAPasswordCannotBeDecryptedAfterASecurityKeyChange(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        CraftStub::install(securityKey: 'a-different-security-key');
        $value = (new PasswordField(['handle' => 'pw']))->normalizeValue($stored, null);

        self::assertFalse($value->isEmpty(), 'Element fell open after a key change');
        self::assertNotSame('hunter2', $value->revealPassword(new RevealToken()));
        self::assertSame($stored, $value->revealPassword(new RevealToken()));
    }

    /**
     * The undecryptable element stays locked in practice: the gate compares the
     * submitted password against the raw ciphertext, which no visitor can supply,
     * and the original password no longer opens it.
     */
    public function testTheOriginalPasswordNoLongerUnlocksAfterAKeyChange(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        CraftStub::install(securityKey: 'a-different-security-key');
        $expected = (new PasswordField(['handle' => 'pw']))
            ->normalizeValue($stored, null)
            ->revealPassword(new RevealToken());

        self::assertFalse(hash_equals($expected, 'hunter2'));
        self::assertFalse(hash_equals($expected, ''));
    }

    // --- the undecryptable state -------------------------------------------

    public function testAValueThatDecryptsIsNotFlagged(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        self::assertFalse($this->field->normalizeValue($stored, null)->isUndecryptable());
    }

    public function testAValueThatCannotBeDecryptedIsFlagged(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        CraftStub::install(securityKey: 'a-different-security-key');

        self::assertTrue((new PasswordField(['handle' => 'pw']))->normalizeValue($stored, null)->isUndecryptable());
    }

    public function testAnUndecodableValueIsFlagged(): void
    {
        self::assertTrue($this->field->normalizeValue('not base64 !!', null)->isUndecryptable());
    }

    /**
     * The bug this guards: re-encrypting an undecryptable value would make the
     * ciphertext the element's real password, permanently and silently, on any
     * later save of the entry. It has to be written back byte-identical.
     */
    public function testAnUndecryptableValueIsWrittenBackUntouched(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        CraftStub::install(securityKey: 'a-different-security-key');
        $field = new PasswordField(['handle' => 'pw']);

        $reserialized = $field->serializeValue($field->normalizeValue($stored, null), null);

        self::assertSame($stored, $reserialized);
    }

    public function testRepeatedSavesLeaveAnUndecryptableValueStable(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        CraftStub::install(securityKey: 'a-different-security-key');
        $field = new PasswordField(['handle' => 'pw']);

        $current = $stored;
        for ($i = 0; $i < 5; $i++) {
            $value = $field->normalizeValue($current, null);
            self::assertTrue($value->isUndecryptable(), "still detectable after $i saves");
            $current = $field->serializeValue($value, null);
        }

        self::assertSame($stored, $current);
    }

    /**
     * The editor's replacement password is encrypted normally, which is what
     * actually clears the undecryptable state.
     */
    public function testEnteringANewPasswordReplacesTheUndecryptableValue(): void
    {
        $field = new PasswordField(['handle' => 'pw']);
        $value = $field->normalizeValueFromRequest(['password' => 'new-password', 'stored' => 'old-ciphertext'], null);

        self::assertFalse($value->isUndecryptable());

        $stored = $field->serializeValue($value, null);

        self::assertSame('new-password', $field->normalizeValue($stored, null)->revealPassword(new RevealToken()));
    }

    /**
     * Saving the entry without touching the field keeps the element gated and
     * keeps the state detectable, rather than clearing the password (which would
     * make the element public) or re-encrypting it.
     */
    public function testLeavingAnUndecryptableValueAloneKeepsItExactlyAsFound(): void
    {
        $field = new PasswordField(['handle' => 'pw']);
        $value = $field->normalizeValueFromRequest(['password' => '', 'stored' => 'old-ciphertext'], null);

        self::assertTrue($value->isUndecryptable());
        self::assertFalse($value->isEmpty(), 'The element would fall open');
        self::assertSame('old-ciphertext', $field->serializeValue($value, null));
    }

    public function testAnEmptyPostedPairClearsTheField(): void
    {
        $field = new PasswordField(['handle' => 'pw']);

        self::assertNull($field->normalizeValueFromRequest(['password' => '', 'stored' => ''], null));
    }

    /**
     * With the toggle off the field renders plain text, but ciphertext is never a
     * password anyone chose, so it stays masked either way.
     */
    public function testStaticHtmlNeverShowsAnUndecryptableValueAsPlainText(): void
    {
        $stored = $this->field->serializeValue(new PasswordValue('hunter2'), null);

        CraftStub::install(securityKey: 'a-different-security-key');
        $field = new PasswordField(['handle' => 'pw', 'showVisibilityToggle' => false]);
        $value = $field->normalizeValue($stored, null);

        $html = $field->getStaticHtml($value, $this->createMock(\craft\base\ElementInterface::class));

        self::assertStringNotContainsString($stored, $html);
        self::assertStringContainsString('••••••••', $html);
    }

    // --- paths that must not leak the plaintext ----------------------------

    public function testTheFieldIsExcludedFromTheGraphqlSchema(): void
    {
        self::assertFalse($this->field->includeInGqlSchema(new GqlSchema()));
    }

    public function testStaticHtmlShowsTheMaskAndKeepsThePlaintextOutOfTheDom(): void
    {
        $element = $this->createMock(\craft\base\ElementInterface::class);
        $html = $this->field->getStaticHtml(new PasswordValue('hunter2'), $element);

        self::assertStringNotContainsString('hunter2', $html);
        self::assertStringContainsString('••••••••', $html);
    }

    /**
     * With the visibility toggle off the field is configured to show plain text,
     * which is the one place static HTML is meant to render the real value.
     */
    public function testStaticHtmlShowsPlaintextOnlyWhenTheToggleIsOff(): void
    {
        $field = new PasswordField(['handle' => 'pw', 'showVisibilityToggle' => false]);
        $element = $this->createMock(\craft\base\ElementInterface::class);

        self::assertStringContainsString('hunter2', $field->getStaticHtml(new PasswordValue('hunter2'), $element));
    }

    public function testStaticHtmlDoesNotResolveALazyValueWhenMasked(): void
    {
        $resolved = false;
        $value = new PasswordValue(function() use (&$resolved): array {
            $resolved = true;
            return ['hunter2', true];
        });

        $this->field->getStaticHtml($value, $this->createMock(\craft\base\ElementInterface::class));

        self::assertFalse($resolved, 'Static render decrypted a value it only needed to mask');
    }
}

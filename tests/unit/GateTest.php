<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\services\Gate;
use bensomething\speakeasy\tests\support\CraftStub;
use bensomething\speakeasy\tests\support\StubFieldLayout;
use craft\base\ElementInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Gate::class)]
final class GateTest extends TestCase
{
    private CraftStub $app;
    private Gate $gate;

    protected function setUp(): void
    {
        [$this->app, , $this->gate] = CraftStub::install();
    }

    protected function tearDown(): void
    {
        CraftStub::uninstall();
    }

    /**
     * @param array<string, mixed> $values field handle => value
     */
    private function element(?StubFieldLayout $layout, array $values = []): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn($layout);
        $element->method('getFieldValue')->willReturnCallback(
            fn(string $handle) => $values[$handle] ?? null
        );

        return $element;
    }

    // --- getPassword() -----------------------------------------------------

    public function testReturnsNullWhenElementHasNoFieldLayout(): void
    {
        self::assertNull($this->gate->getPassword($this->element(null)));
    }

    public function testReturnsNullWhenLayoutHasNoPasswordField(): void
    {
        $layout = new StubFieldLayout([new \craft\fields\PlainText(['handle' => 'body'])]);

        self::assertNull($this->gate->getPassword($this->element($layout)));
    }

    public function testReturnsNullWhenPasswordFieldIsEmpty(): void
    {
        $field = new PasswordField(['handle' => 'pw']);
        $layout = new StubFieldLayout([$field]);
        $element = $this->element($layout, ['pw' => new PasswordValue('')]);

        self::assertNull($this->gate->getPassword($element));
    }

    public function testReturnsNullWhenPasswordFieldValueIsNull(): void
    {
        $field = new PasswordField(['handle' => 'pw']);
        $layout = new StubFieldLayout([$field]);

        self::assertNull($this->gate->getPassword($this->element($layout, [])));
    }

    public function testReturnsThePlaintextPassword(): void
    {
        $field = new PasswordField(['handle' => 'pw']);
        $layout = new StubFieldLayout([$field]);
        $element = $this->element($layout, ['pw' => new PasswordValue('hunter2')]);

        self::assertSame('hunter2', $this->gate->getPassword($element));
    }

    public function testSkipsNonPasswordFieldsWhenLocatingThePassword(): void
    {
        $layout = new StubFieldLayout([
            new \craft\fields\PlainText(['handle' => 'body']),
            new PasswordField(['handle' => 'pw']),
        ]);
        $element = $this->element($layout, ['pw' => new PasswordValue('hunter2')]);

        self::assertSame('hunter2', $this->gate->getPassword($element));
    }

    /**
     * The field's inline warning tells editors only the first Password field
     * gates the element. This is the behaviour that warning describes.
     */
    public function testUsesTheFirstSetPasswordFieldInTheLayout(): void
    {
        $layout = new StubFieldLayout([
            new PasswordField(['handle' => 'first']),
            new PasswordField(['handle' => 'second']),
        ]);
        $element = $this->element($layout, [
            'first' => new PasswordValue('first-password'),
            'second' => new PasswordValue('second-password'),
        ]);

        self::assertSame('first-password', $this->gate->getPassword($element));
    }

    public function testFallsThroughAnEmptyPasswordFieldToALaterSetOne(): void
    {
        $layout = new StubFieldLayout([
            new PasswordField(['handle' => 'first']),
            new PasswordField(['handle' => 'second']),
        ]);
        $element = $this->element($layout, [
            'first' => new PasswordValue(''),
            'second' => new PasswordValue('second-password'),
        ]);

        self::assertSame('second-password', $this->gate->getPassword($element));
    }

    // --- unlock() / isUnlocked() -------------------------------------------

    public function testIsNotUnlockedBeforeUnlocking(): void
    {
        self::assertFalse($this->gate->isUnlocked('hunter2'));
    }

    public function testUnlockThenIsUnlocked(): void
    {
        $this->gate->unlock('hunter2');

        self::assertTrue($this->gate->isUnlocked('hunter2'));
    }

    public function testUnlockingOnePasswordDoesNotUnlockAnother(): void
    {
        $this->gate->unlock('hunter2');

        self::assertFalse($this->gate->isUnlocked('correct-horse'));
    }

    /**
     * Shared unlock: the session is keyed by password, not by element, so two
     * elements sharing a password are unlocked by one entry. This is the same
     * assertion from the gate's point of view, since it only ever sees the
     * password string.
     */
    public function testUnlockIsKeyedByPasswordSoMatchingElementsShareIt(): void
    {
        $this->gate->unlock('shared-password');

        self::assertTrue($this->gate->isUnlocked('shared-password'));
        self::assertCount(1, $this->app->session->data[Gate::SESSION_KEY]);
    }

    public function testSessionNeverStoresThePasswordItself(): void
    {
        $this->gate->unlock('hunter2');

        $stored = $this->app->session->data[Gate::SESSION_KEY];
        self::assertNotContains('hunter2', array_keys($stored));
        self::assertStringNotContainsString('hunter2', json_encode($stored));
    }

    /**
     * Tokens are HMACed with the security key, so a session store lifted from
     * one install can't be replayed against another (or run against a wordlist).
     */
    public function testTokensAreKeyedToTheSecurityKey(): void
    {
        $this->gate->unlock('hunter2');
        $tokenA = array_key_first($this->app->session->data[Gate::SESSION_KEY]);

        [$appB, , $gateB] = CraftStub::install(securityKey: 'a-different-security-key');
        $gateB->unlock('hunter2');
        $tokenB = array_key_first($appB->session->data[Gate::SESSION_KEY]);

        self::assertNotSame($tokenA, $tokenB);
    }

    public function testAnUnlockDoesNotSurviveASecurityKeyChange(): void
    {
        $this->gate->unlock('hunter2');
        $tokens = $this->app->session->data[Gate::SESSION_KEY];

        [$appB, , $gateB] = CraftStub::install(securityKey: 'a-different-security-key');
        $appB->session->data[Gate::SESSION_KEY] = $tokens;

        self::assertFalse($gateB->isUnlocked('hunter2'));
    }

    // --- unlock duration ---------------------------------------------------

    public function testUnlockWithinTheDurationHolds(): void
    {
        [$app, , $gate] = CraftStub::install(['unlockDurationSeconds' => 3600]);
        $app->session->data[Gate::SESSION_KEY] = $this->tokensFor($gate, 'hunter2', time() - 60);

        self::assertTrue($gate->isUnlocked('hunter2'));
    }

    public function testUnlockExpiresAfterTheDuration(): void
    {
        [$app, , $gate] = CraftStub::install(['unlockDurationSeconds' => 60]);
        $app->session->data[Gate::SESSION_KEY] = $this->tokensFor($gate, 'hunter2', time() - 3600);

        self::assertFalse($gate->isUnlocked('hunter2'));
    }

    public function testZeroDurationMeansNoExpiryBeyondTheSession(): void
    {
        [$app, , $gate] = CraftStub::install(['unlockDurationSeconds' => 0]);
        $app->session->data[Gate::SESSION_KEY] = $this->tokensFor($gate, 'hunter2', time() - 99999);

        self::assertTrue($gate->isUnlocked('hunter2'));
    }

    // --- malformed session data --------------------------------------------

    /**
     * A session store carrying anything other than token => timestamp pairs is
     * ignored rather than trusted. It must never resolve to "unlocked".
     */
    public function testMalformedSessionDataDoesNotUnlock(): void
    {
        $this->gate->unlock('hunter2');
        $token = array_key_first($this->app->session->data[Gate::SESSION_KEY]);

        foreach ([
            'not an array' => 'unlocked',
            'non-int timestamp' => [$token => 'yes'],
            'null timestamp' => [$token => null],
            'bool timestamp' => [$token => true],
            'nested array' => [$token => ['unlockedAt' => 123]],
            'numeric-string timestamp' => [$token => '1700000000'],
            'float timestamp' => [$token => 1700000000.0],
        ] as $label => $value) {
            $this->app->session->data[Gate::SESSION_KEY] = $value;
            self::assertFalse($this->gate->isUnlocked('hunter2'), $label);
        }
    }

    public function testMalformedEntriesDoNotDiscardValidOnes(): void
    {
        $this->gate->unlock('hunter2');
        $tokens = $this->app->session->data[Gate::SESSION_KEY];
        $tokens['junk'] = 'not a timestamp';
        $this->app->session->data[Gate::SESSION_KEY] = $tokens;

        self::assertTrue($this->gate->isUnlocked('hunter2'));
    }

    /**
     * Builds the session payload the gate would have written, so duration tests
     * can backdate an unlock without reaching into the token derivation.
     */
    private function tokensFor(Gate $gate, string $password, int $unlockedAt): array
    {
        $gate->unlock($password);
        $tokens = \Craft::$app->getSession()->get(Gate::SESSION_KEY);

        return [array_key_first($tokens) => $unlockedAt];
    }
}

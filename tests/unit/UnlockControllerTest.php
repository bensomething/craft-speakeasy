<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use bensomething\speakeasy\controllers\UnlockController;
use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\models\Settings;
use bensomething\speakeasy\services\Gate;
use bensomething\speakeasy\tests\support\CraftStub;
use bensomething\speakeasy\tests\support\StubFieldLayout;
use bensomething\speakeasy\tests\support\TestUnlockController;
use craft\base\Element;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/**
 * The unlock action: what it takes to get a token, and the things that must
 * happen before a submitted password is even looked at.
 */
#[CoversClass(UnlockController::class)]
final class UnlockControllerTest extends TestCase
{
    private const ELEMENT_ID = 42;
    private const ELEMENT_URL = 'https://example.test/secret';

    private CraftStub $app;
    private Settings $settings;
    private Gate $gate;
    private TestUnlockController $controller;

    protected function setUp(): void
    {
        [$this->app, $this->settings, $this->gate] = CraftStub::install();
        unset($_SERVER[Settings::LOCKDOWN_ENV]);

        $this->app->elements->add(self::ELEMENT_ID, $this->protectedElement('hunter2'));
        $this->controller = TestUnlockController::make();
    }

    protected function tearDown(): void
    {
        unset($_SERVER[Settings::LOCKDOWN_ENV]);
        CraftStub::uninstall();
    }

    /**
     * Mocks the Element base class rather than the interface, since the attempt
     * key is built from the element's `id` property, which only the class declares.
     */
    private function protectedElement(?string $password, int $id = self::ELEMENT_ID, ?string $url = self::ELEMENT_URL): Element
    {
        $field = new PasswordField(['handle' => 'pw']);
        $element = $this->createMock(Element::class);
        $element->method('getFieldLayout')->willReturn(new StubFieldLayout([$field]));
        $element->method('getFieldValue')->willReturn($password === null ? null : new PasswordValue($password));
        $element->method('getUrl')->willReturn($url);
        $element->id = $id;

        return $element;
    }

    private function submit(?string $password, int|string|null $elementId = self::ELEMENT_ID): void
    {
        $this->app->request->bodyParams = ['elementId' => $elementId, 'password' => $password];
        $this->controller->actionIndex();
    }

    private function assertNotUnlocked(string $password = 'hunter2'): void
    {
        self::assertFalse($this->gate->isUnlocked($password));
    }

    // --- the happy path -----------------------------------------------------

    public function testTheCorrectPasswordUnlocksAndRedirectsBack(): void
    {
        $this->submit('hunter2');

        self::assertTrue($this->gate->isUnlocked('hunter2'));
        self::assertSame(self::ELEMENT_URL, $this->controller->redirectedTo);
        self::assertSame([], $this->app->session->errors);
    }

    public function testTheActionRequiresAPostRequest(): void
    {
        $this->submit('hunter2');

        self::assertTrue($this->controller->requiredPost);
    }

    public function testASuccessfulUnlockClearsTheAttemptCounter(): void
    {
        $this->submit('wrong');
        $this->submit('hunter2');

        self::assertSame([], $this->app->cache->data);
    }

    // --- the unhappy paths --------------------------------------------------

    public function testAWrongPasswordDoesNotUnlock(): void
    {
        $this->submit('wrong');

        $this->assertNotUnlocked();
        self::assertSame(['Incorrect password'], $this->app->session->errors);
        self::assertSame(self::ELEMENT_URL, $this->controller->redirectedTo);
    }

    public function testAnEmptyPasswordDoesNotUnlock(): void
    {
        $this->submit('');

        $this->assertNotUnlocked();
    }

    public function testAMissingPasswordParamDoesNotUnlock(): void
    {
        $this->submit(null);

        $this->assertNotUnlocked();
    }

    /**
     * An element with no password has nothing to compare against. It must not be
     * unlockable by submitting an empty string.
     */
    public function testAnUnprotectedElementCannotBeUnlockedWithAnEmptyPassword(): void
    {
        $this->app->elements->add(99, $this->protectedElement(null, id: 99));
        $this->app->request->bodyParams = ['elementId' => 99, 'password' => ''];

        $this->controller->actionIndex();

        self::assertSame([], $this->app->session->data);
    }

    public function testAConfiguredErrorMessageIsUsed(): void
    {
        $this->settings->errorText = 'Nope, try again';

        $this->submit('wrong');

        self::assertSame(['Nope, try again'], $this->app->session->errors);
    }

    public function testAnUnknownElementIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->submit('hunter2', 9999);
    }

    public function testAMissingElementIdIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->submit('hunter2', null);
    }

    public function testAnElementWithNoUrlIsRejected(): void
    {
        $this->app->elements->add(77, $this->protectedElement('hunter2', id: 77, url: null));
        $this->app->request->bodyParams = ['elementId' => 77, 'password' => 'hunter2'];

        $this->expectException(NotFoundHttpException::class);

        $this->controller->actionIndex();
    }

    // --- lockdown -----------------------------------------------------------

    /**
     * Lockdown is checked before any password work, so posting straight to this
     * action can't bank a valid token to spend the moment lockdown lifts.
     */
    public function testLockdownRefusesEvenTheCorrectPassword(): void
    {
        $_SERVER[Settings::LOCKDOWN_ENV] = '1';

        try {
            $this->submit('hunter2');
            self::fail('Expected the unlock action to be refused under lockdown');
        } catch (ForbiddenHttpException) {
            // expected
        }

        $this->assertNotUnlocked();
        self::assertSame([], $this->app->session->data);
    }

    public function testLockdownRefusesBeforeSpendingAnAttempt(): void
    {
        $_SERVER[Settings::LOCKDOWN_ENV] = '1';

        try {
            $this->submit('wrong');
        } catch (ForbiddenHttpException) {
            // expected
        }

        self::assertSame([], $this->app->cache->data, 'Lockdown consumed an attempt before refusing');
    }

    // --- rate limiting ------------------------------------------------------

    public function testFailedAttemptsAreCountedPerIpAndElement(): void
    {
        $this->submit('wrong');
        $this->submit('wrong');

        self::assertSame([2], array_values($this->app->cache->data));
    }

    /**
     * The counter is bumped before the password is compared, so a correct guess
     * arriving in parallel with a flood can't slip past a non-atomic counter.
     */
    public function testASuccessfulAttemptAlsoCountsBeforeBeingChecked(): void
    {
        $this->settings->maxAttempts = 1;

        $this->submit('hunter2');
        self::assertTrue($this->gate->isUnlocked('hunter2'), 'The first attempt should be within the limit');

        // Counter cleared on success, so the next wrong guess starts from one.
        $this->submit('wrong');
        self::assertSame([1], array_values($this->app->cache->data));
    }

    public function testAttemptsBeyondTheLimitAreRefusedWithoutCheckingThePassword(): void
    {
        $this->settings->maxAttempts = 3;

        for ($i = 0; $i < 3; $i++) {
            $this->submit('wrong');
        }

        // Over the limit now, so even the right password is turned away.
        $this->submit('hunter2');

        $this->assertNotUnlocked();
        self::assertSame(['Too many attempts. Please try again later.'], array_slice($this->app->session->errors, -1));
    }

    public function testTheLimitIsPerPassword(): void
    {
        $this->settings->maxAttempts = 1;
        $this->app->elements->add(88, $this->protectedElement('other-password', id: 88));

        $this->submit('wrong');
        $this->submit('wrong');
        $this->assertNotUnlocked();

        // An element behind a different password is a different secret, so it has
        // its own counter and is still reachable.
        $this->app->request->bodyParams = ['elementId' => 88, 'password' => 'other-password'];
        $this->controller->actionIndex();

        self::assertTrue($this->gate->isUnlocked('other-password'));
    }

    /**
     * Unlocking is keyed by password, so one unlock opens every element sharing
     * it. The attempt budget has to be keyed the same way, or each extra element
     * behind the password (and each draft and revision, which carry a copy of the
     * field) multiplies the guesses available against that one secret.
     */
    public function testElementsSharingAPasswordShareTheLimit(): void
    {
        $this->settings->maxAttempts = 1;
        $this->app->elements->add(88, $this->protectedElement('hunter2', id: 88));

        $this->submit('wrong');

        $this->app->request->bodyParams = ['elementId' => 88, 'password' => 'hunter2'];
        $this->controller->actionIndex();

        $this->assertNotUnlocked();
        self::assertSame(['Too many attempts. Please try again later.'], array_slice($this->app->session->errors, -1));
        self::assertCount(1, $this->app->cache->data, 'Both elements should share one counter');
    }

    public function testTheLimitIsPerIp(): void
    {
        $this->settings->maxAttempts = 1;

        $this->submit('wrong');
        $this->submit('wrong');
        $this->assertNotUnlocked();

        $this->app->request->userIp = '198.51.100.7';
        $this->submit('hunter2');

        self::assertTrue($this->gate->isUnlocked('hunter2'));
    }

    public function testTheCounterExpiresWithTheConfiguredWindow(): void
    {
        $this->settings->attemptWindowSeconds = 900;

        $this->submit('wrong');

        self::assertSame([900], array_values($this->app->cache->ttls));
    }

    public function testRateLimitingCanBeTurnedOff(): void
    {
        $this->settings->maxAttempts = 0;

        for ($i = 0; $i < 20; $i++) {
            $this->submit('wrong');
        }
        $this->submit('hunter2');

        self::assertTrue($this->gate->isUnlocked('hunter2'));
        self::assertSame([], $this->app->cache->data, 'No counter should be kept when the limit is off');
    }

    /**
     * A null/dummy cache driver can't hold a counter, so the lockout is inert and
     * every attempt reads as the first. Documented as a caveat; pinned here so it
     * stays a documented caveat rather than becoming a silent refusal of everyone.
     */
    public function testANullCacheDriverLeavesTheLockoutInertRatherThanBlockingEveryone(): void
    {
        $this->app->cache->null = true;
        $this->settings->maxAttempts = 1;

        $this->submit('wrong');
        $this->submit('hunter2');

        self::assertTrue($this->gate->isUnlocked('hunter2'));
    }

    /**
     * If the mutex can't be taken the counter can't be trusted, so the attempt is
     * refused rather than waved through.
     */
    public function testAnUnavailableMutexFailsClosed(): void
    {
        $this->app->mutex->failToAcquire = true;

        $this->submit('hunter2');

        $this->assertNotUnlocked();
        self::assertSame(['Too many attempts. Please try again later.'], $this->app->session->errors);
    }

    public function testTheMutexIsAlwaysReleased(): void
    {
        $this->submit('wrong');
        $this->submit('hunter2');

        self::assertSame([], $this->app->mutex->held);
    }

    // --- what the counter key gives away ------------------------------------

    public function testTheAttemptKeyDoesNotContainTheRawClientIp(): void
    {
        $this->submit('wrong');

        $key = array_key_first($this->app->cache->data);
        self::assertStringNotContainsString('203.0.113.1', $key);
    }

    /**
     * The key now covers the password, so it has to be keyed with the security
     * key rather than plainly hashed: a bare digest of a short IP + password pair
     * is worth running a wordlist against if the cache ever leaks.
     */
    public function testTheAttemptKeyDoesNotGiveUpThePassword(): void
    {
        $this->submit('wrong');

        $key = array_key_first($this->app->cache->data);
        self::assertStringNotContainsString('hunter2', $key);
        self::assertStringNotContainsString(md5('203.0.113.1:hunter2'), $key);
    }
}

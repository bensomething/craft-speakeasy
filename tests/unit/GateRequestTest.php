<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\models\Settings;
use bensomething\speakeasy\services\Gate;
use bensomething\speakeasy\tests\support\CraftStub;
use bensomething\speakeasy\tests\support\StubFieldLayout;
use craft\base\ElementInterface;
use craft\elements\User;
use craft\events\TemplateEvent;
use craft\web\View;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The gate as it runs on a front-end request: who gets through, who gets the
 * unlock screen, and what the protected response is allowed to say.
 */
#[CoversClass(Gate::class)]
final class GateRequestTest extends TestCase
{
    private CraftStub $app;
    private Settings $settings;
    private Gate $gate;

    protected function setUp(): void
    {
        [$this->app, $this->settings, $this->gate] = CraftStub::install();
        unset($_SERVER[Settings::LOCKDOWN_ENV]);
    }

    protected function tearDown(): void
    {
        unset($_SERVER[Settings::LOCKDOWN_ENV]);
        CraftStub::uninstall();
    }

    private function protectedElement(string $password = 'hunter2', bool $canView = false): ElementInterface
    {
        $field = new PasswordField(['handle' => 'pw']);
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn(new StubFieldLayout([$field]));
        $element->method('getFieldValue')->willReturn(new PasswordValue($password));
        $element->method('canView')->willReturn($canView);

        return $element;
    }

    private function publicElement(): ElementInterface
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getFieldLayout')->willReturn(new StubFieldLayout([]));

        return $element;
    }

    private function event(string $templateMode = View::TEMPLATE_MODE_SITE): TemplateEvent
    {
        return new TemplateEvent([
            'template' => 'the/original/template',
            'variables' => ['existing' => 'kept'],
            'templateMode' => $templateMode,
        ]);
    }

    private function lockDown(): void
    {
        $_SERVER[Settings::LOCKDOWN_ENV] = '1';
    }

    private function assertRendersOriginalTemplate(TemplateEvent $event): void
    {
        self::assertSame('the/original/template', $event->template);
    }

    private function assertRendersUnlockScreen(TemplateEvent $event): void
    {
        self::assertSame('speakeasy/_unlock', $event->template);
    }

    // --- when the gate stands aside ----------------------------------------

    public function testIgnoresControlPanelRequests(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $event = $this->event(View::TEMPLATE_MODE_CP);

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
    }

    public function testIgnoresRequestsWithNoMatchedElement(): void
    {
        $this->app->urlManager->matchedElement = null;
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
    }

    public function testIgnoresUnprotectedElements(): void
    {
        $this->app->urlManager->matchedElement = $this->publicElement();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
        self::assertNull($this->app->response->headers->get('X-Robots-Tag'));
    }

    public function testAnUnlockedVisitorGetsTheRealTemplate(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $this->gate->unlock('hunter2');
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
    }

    // --- when the gate closes ----------------------------------------------

    public function testAnAnonymousVisitorGetsTheUnlockScreen(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
    }

    public function testAnUnlockForADifferentPasswordDoesNotOpenThisElement(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement('hunter2');
        $this->gate->unlock('some-other-password');
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
    }

    public function testAConfiguredCustomTemplateIsUsedInsteadOfTheBundledOne(): void
    {
        $this->settings->template = 'my/own/unlock';
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        self::assertSame('my/own/unlock', $event->template);
    }

    public function testTheUnlockScreenKeepsExistingVariablesAndAddsItsOwn(): void
    {
        $this->app->urlManager->matchedElement = $element = $this->protectedElement();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        self::assertSame('kept', $event->variables['existing']);
        self::assertSame($element, $event->variables['element']);
        self::assertFalse($event->variables['lockdown']);
    }

    /**
     * The unlock screen is a challenge, not a refusal, so it stays a 200. Only
     * lockdown, which has no remedy, becomes a 403.
     */
    public function testTheUnlockScreenIsNotAnErrorResponse(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();

        $this->gate->handleBeforeRenderPageTemplate($this->event());

        self::assertSame(200, $this->app->response->getStatusCode());
    }

    // --- protected responses are never cached or indexed --------------------

    public function testAProtectedResponseIsMarkedNoStoreAndNoindex(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();

        $this->gate->handleBeforeRenderPageTemplate($this->event());

        self::assertStringContainsString('no-store', $this->app->response->headers->get('Cache-Control'));
        self::assertSame('noindex', $this->app->response->headers->get('X-Robots-Tag'));
    }

    /**
     * The headers are set before any bypass or unlock check, so they also cover
     * the responses that do render the protected content.
     */
    public function testAnUnlockedResponseIsStillNeverCachedOrIndexed(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $this->gate->unlock('hunter2');

        $this->gate->handleBeforeRenderPageTemplate($this->event());

        self::assertStringContainsString('no-store', $this->app->response->headers->get('Cache-Control'));
        self::assertSame('noindex', $this->app->response->headers->get('X-Robots-Tag'));
    }

    public function testABypassingCpUserResponseIsStillNeverCachedOrIndexed(): void
    {
        $this->app->user->identity = $this->createMock(User::class);
        $this->app->urlManager->matchedElement = $this->protectedElement(canView: true);

        $this->gate->handleBeforeRenderPageTemplate($this->event());

        self::assertStringContainsString('no-store', $this->app->response->headers->get('Cache-Control'));
        self::assertSame('noindex', $this->app->response->headers->get('X-Robots-Tag'));
    }

    // --- CP user bypass -----------------------------------------------------

    public function testAUserWhoCanViewTheElementBypassesTheGate(): void
    {
        $this->app->user->identity = $this->createMock(User::class);
        $this->app->urlManager->matchedElement = $this->protectedElement(canView: true);
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
    }

    /**
     * The bypass is not "any logged-in user". A user without view permission on
     * the element is treated like any other visitor.
     */
    public function testALoggedInUserWhoCannotViewTheElementDoesNotBypass(): void
    {
        $this->app->user->identity = $this->createMock(User::class);
        $this->app->urlManager->matchedElement = $this->protectedElement(canView: false);
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
    }

    public function testTheBypassCanBeTurnedOff(): void
    {
        $this->settings->bypassForCpUsers = false;
        $this->app->user->identity = $this->createMock(User::class);
        $this->app->urlManager->matchedElement = $this->protectedElement(canView: true);
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
    }

    public function testAnAnonymousVisitorNeverBypassesEvenWhenTheBypassIsOn(): void
    {
        $this->app->user->identity = null;
        $this->app->urlManager->matchedElement = $this->protectedElement(canView: true);
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
    }

    // --- lockdown -----------------------------------------------------------

    public function testLockdownClosesAProtectedElementToAnonymousVisitors(): void
    {
        $this->lockDown();
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
        self::assertSame(403, $this->app->response->getStatusCode());
        self::assertTrue($event->variables['lockdown']);
    }

    /**
     * Lockdown outranks an unlock already banked in the session, so a visitor who
     * got in before it was switched on is closed out too.
     */
    public function testLockdownOverridesAnExistingUnlock(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $this->gate->unlock('hunter2');
        $this->lockDown();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersUnlockScreen($event);
        self::assertSame(403, $this->app->response->getStatusCode());
    }

    public function testLockdownLeavesTheSessionTokenIntactForWhenItLifts(): void
    {
        $this->app->urlManager->matchedElement = $this->protectedElement();
        $this->gate->unlock('hunter2');
        $tokensBefore = $this->app->session->data[Gate::SESSION_KEY];

        $this->lockDown();
        $this->gate->handleBeforeRenderPageTemplate($this->event());

        self::assertSame($tokensBefore, $this->app->session->data[Gate::SESSION_KEY]);
    }

    /**
     * Lockdown is a public-facing measure. Editors and live preview keep working,
     * which is what makes it usable on a site that's still being worked on.
     */
    public function testLockdownDoesNotCloseOutABypassingCpUser(): void
    {
        $this->lockDown();
        $this->app->user->identity = $this->createMock(User::class);
        $this->app->urlManager->matchedElement = $this->protectedElement(canView: true);
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
        self::assertSame(200, $this->app->response->getStatusCode());
    }

    public function testLockdownDoesNotAffectUnprotectedElements(): void
    {
        $this->lockDown();
        $this->app->urlManager->matchedElement = $this->publicElement();
        $event = $this->event();

        $this->gate->handleBeforeRenderPageTemplate($event);

        $this->assertRendersOriginalTemplate($event);
        self::assertSame(200, $this->app->response->getStatusCode());
    }

    /**
     * Any non-empty value counts as locked down, erring towards closed. Craft
     * normalises "false"/"0" before the setting sees them.
     */
    public function testLockdownEnvValuesAreInterpretedConservatively(): void
    {
        foreach (['1' => true, 'true' => true, 'yes' => true, 'anything' => true, '0' => false, 'false' => false, '' => false] as $env => $expected) {
            $_SERVER[Settings::LOCKDOWN_ENV] = $env;
            self::assertSame($expected, $this->settings->isLockedDown(), "env value '$env'");
        }

        unset($_SERVER[Settings::LOCKDOWN_ENV]);
        self::assertFalse($this->settings->isLockedDown(), 'unset');
    }
}

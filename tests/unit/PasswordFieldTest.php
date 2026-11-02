<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use bensomething\speakeasy\fields\conditions\HasPasswordConditionRule;
use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\fields\RevealToken;
use craft\base\ElementContainerFieldInterface;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * Only the value handling that doesn't need a Craft application. Anything routed
 * through Craft's security component (encrypt/decrypt) or the CP's HTML helpers
 * is left to manual testing in a real install.
 */
#[CoversClass(PasswordField::class)]
class PasswordFieldTest extends TestCase
{
    public function testAValueFromTheEditFormIsWrappedAsPlaintext(): void
    {
        $value = (new PasswordField())->normalizeValueFromRequest('hunter2', null);

        $this->assertInstanceOf(PasswordValue::class, $value);
        $this->assertSame('hunter2', $value->revealPassword(new RevealToken()));
    }

    public function testAnEmptySubmittedValueBecomesNull(): void
    {
        $this->assertNull((new PasswordField())->normalizeValueFromRequest('', null));
        $this->assertNull((new PasswordField())->normalizeValueFromRequest(null, null));
    }

    public function testAnAlreadyNormalizedValueIsPassedThrough(): void
    {
        $field = new PasswordField();
        $value = new PasswordValue('hunter2');

        $this->assertSame($value, $field->normalizeValueFromRequest($value, null));
        $this->assertSame($value, $field->normalizeValue($value, null));
    }

    public function testAnEmptyStoredValueBecomesNull(): void
    {
        $this->assertNull((new PasswordField())->normalizeValue('', null));
        $this->assertNull((new PasswordField())->normalizeValue(null, null));
    }

    public function testAnUndecodableStoredValueStaysLockedRatherThanEmpty(): void
    {
        // A value that isn't valid base64 can't be decrypted; the element must stay
        // gated (non-empty password) instead of falling open.
        $value = (new PasswordField())->normalizeValue('not base64 !!', null);

        $this->assertInstanceOf(PasswordValue::class, $value);
        $this->assertFalse($value->isEmpty());
        $this->assertSame('not base64 !!', $value->revealPassword(new RevealToken()));
    }

    public function testAnEmptyValueSerializesToNull(): void
    {
        $field = new PasswordField();

        $this->assertNull($field->serializeValue(null, null));
        $this->assertNull($field->serializeValue('', null));
        $this->assertNull($field->serializeValue(new PasswordValue(''), null));
    }

    public function testThePasswordIsNeverIndexedForSearch(): void
    {
        $element = $this->createMock(ElementInterface::class);

        $this->assertSame('', (new PasswordField())->getSearchKeywords(new PasswordValue('hunter2'), $element));
    }

    /**
     * An element has one password, so a second instance of the same field would
     * be inert.
     */
    public function testTheFieldCannotBePlacedTwiceInALayout(): void
    {
        $this->assertFalse(PasswordField::isMultiInstance());
    }

    public function testTheFieldFiltersByPresenceOnly(): void
    {
        $this->assertSame(HasPasswordConditionRule::class, (new PasswordField())->getElementConditionRuleType());
    }

    /**
     * An element with no URI format has no page of its own for the gate to guard,
     * so inputHtml warns. inputHtml itself needs the CP HTML helpers, so the
     * warning predicate is exercised directly instead.
     */
    public function testANestedElementWithoutAUrlIsFlagged(): void
    {
        $element = $this->createMock(NestedElementInterface::class);
        $element->method('getField')->willReturn($this->createMock(ElementContainerFieldInterface::class));
        $element->method('getUriFormat')->willReturn(null);

        $this->assertTrue($this->hasNoUrl($element));
    }

    /**
     * URLs are set per section and site, not per entry type, so an entry in a
     * section without URLs is flagged even though entries as a type have them.
     */
    public function testATopLevelElementWithoutAUrlIsFlagged(): void
    {
        $element = $this->createMock(ElementInterface::class);
        $element->method('getUriFormat')->willReturn(null);

        $this->assertTrue($this->hasNoUrl($element));
    }

    public function testAnElementWithAUrlIsNotFlagged(): void
    {
        // Includes a container field that gives its nested elements a URI format,
        // which produces real pages the gate can protect.
        $nested = $this->createMock(NestedElementInterface::class);
        $nested->method('getField')->willReturn($this->createMock(ElementContainerFieldInterface::class));
        $nested->method('getUriFormat')->willReturn('{slug}');

        $topLevel = $this->createMock(ElementInterface::class);
        $topLevel->method('getUriFormat')->willReturn('news/{slug}');

        $this->assertFalse($this->hasNoUrl($nested));
        $this->assertFalse($this->hasNoUrl($topLevel));
    }

    public function testMisconfiguredOwnershipIsTreatedAsHavingNoUrl(): void
    {
        $element = $this->createMock(NestedElementInterface::class);
        $element->method('getField')->willReturn($this->createMock(ElementContainerFieldInterface::class));
        $element->method('getUriFormat')->willThrowException(new RuntimeException('bad ownership'));

        $this->assertTrue($this->hasNoUrl($element));
    }

    public function testNoElementIsNeverFlagged(): void
    {
        $this->assertFalse($this->hasNoUrl(null));
    }

    private function hasNoUrl(?ElementInterface $element): bool
    {
        $method = new ReflectionMethod(PasswordField::class, 'hasNoUrl');

        return $method->invoke(new PasswordField(), $element);
    }
}

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
     * A second instance of the same field would be inert, since only the first
     * Password field in a layout gates the element.
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
     * A nested element (e.g. a Matrix block) with no URI format has no page of its
     * own for the gate to guard, so inputHtml warns. inputHtml itself needs the CP
     * HTML helpers, so the warning predicate is exercised directly instead.
     */
    public function testANestedElementWithoutAUrlIsFlagged(): void
    {
        $element = $this->createMock(NestedElementInterface::class);
        $element->method('getField')->willReturn($this->createMock(ElementContainerFieldInterface::class));
        $element->method('getUriFormat')->willReturn(null);

        $this->assertTrue($this->isNestedWithoutUrl($element));
    }

    public function testATopLevelElementIsNotFlaggedAsNested(): void
    {
        // getField() is null when the entry belongs to a section rather than a
        // container field, so it isn't nested and the layout-level checks apply.
        $element = $this->createMock(NestedElementInterface::class);
        $element->method('getField')->willReturn(null);

        $this->assertFalse($this->isNestedWithoutUrl($element));
    }

    public function testANestedElementWithItsOwnUrlIsNotFlagged(): void
    {
        // A container field that gives its nested elements a URI format produces
        // real pages the gate can protect, so no warning.
        $element = $this->createMock(NestedElementInterface::class);
        $element->method('getField')->willReturn($this->createMock(ElementContainerFieldInterface::class));
        $element->method('getUriFormat')->willReturn('{slug}');

        $this->assertFalse($this->isNestedWithoutUrl($element));
    }

    public function testMisconfiguredNestedOwnershipIsTreatedAsHavingNoUrl(): void
    {
        $element = $this->createMock(NestedElementInterface::class);
        $element->method('getField')->willReturn($this->createMock(ElementContainerFieldInterface::class));
        $element->method('getUriFormat')->willThrowException(new RuntimeException('bad ownership'));

        $this->assertTrue($this->isNestedWithoutUrl($element));
    }

    public function testANonNestedElementIsNeverFlagged(): void
    {
        $this->assertFalse($this->isNestedWithoutUrl($this->createMock(ElementInterface::class)));
        $this->assertFalse($this->isNestedWithoutUrl(null));
    }

    private function isNestedWithoutUrl(?ElementInterface $element): bool
    {
        $method = new ReflectionMethod(PasswordField::class, 'isNestedWithoutUrl');

        return $method->invoke(new PasswordField(), $element);
    }
}

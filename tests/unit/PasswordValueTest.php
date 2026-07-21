<?php

declare(strict_types=1);

namespace bensomething\sesame\tests\unit;

use bensomething\sesame\fields\PasswordValue;
use bensomething\sesame\fields\RevealToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordValue::class)]
class PasswordValueTest extends TestCase
{
    public function testStringContextRendersTheMaskNotThePlaintext(): void
    {
        $value = new PasswordValue('hunter2');

        $this->assertSame('••••••••', (string) $value);
        $this->assertStringNotContainsString('hunter2', (string) $value);
    }

    public function testRevealWithoutATokenFallsBackToTheMask(): void
    {
        $value = new PasswordValue('hunter2');

        // What a Twig accessor call — `{{ entry.field.revealPassword }}` — gets.
        $this->assertSame('••••••••', $value->revealPassword());
    }

    public function testRevealWithATokenReturnsThePlaintext(): void
    {
        $value = new PasswordValue('hunter2');

        $this->assertSame('hunter2', $value->revealPassword(new RevealToken()));
    }

    public function testAnEmptyValueMasksToAnEmptyString(): void
    {
        $value = new PasswordValue('');

        $this->assertTrue($value->isEmpty());
        $this->assertSame('', (string) $value);
        $this->assertSame('', $value->revealPassword(new RevealToken()));
    }

    public function testANonEmptyValueIsNotEmpty(): void
    {
        $this->assertFalse((new PasswordValue('hunter2'))->isEmpty());
    }

    public function testALazyValueIsNotResolvedByMaskingOrPresenceChecks(): void
    {
        $resolved = false;
        $value = new PasswordValue(function() use (&$resolved): string {
            $resolved = true;
            return 'hunter2';
        });

        $this->assertSame('••••••••', (string) $value);
        $this->assertFalse($value->isEmpty());
        $this->assertSame('••••••••', $value->revealPassword());

        $this->assertFalse($resolved, 'Deferred value was resolved without a reveal token');
    }

    public function testALazyValueIsResolvedOnceOnGuardedReveal(): void
    {
        $calls = 0;
        $value = new PasswordValue(function() use (&$calls): string {
            $calls++;
            return 'hunter2';
        });

        $this->assertSame('hunter2', $value->revealPassword(new RevealToken()));
        $this->assertSame('hunter2', $value->revealPassword(new RevealToken()));
        $this->assertSame(1, $calls, 'Deferred value was resolved more than once');
    }

    public function testAnUnresolvedLazyValueIsTreatedAsSet(): void
    {
        // Only ever constructed for a stored (non-empty) value, so it must count as
        // set — otherwise the gate would let a protected element through unlocked.
        $value = new PasswordValue(fn(): string => 'hunter2');

        $this->assertFalse($value->isEmpty());
        $this->assertSame('••••••••', (string) $value);
    }
}

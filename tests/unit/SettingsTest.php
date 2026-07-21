<?php

declare(strict_types=1);

namespace bensomething\sesame\tests\unit;

use bensomething\sesame\models\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Settings::class)]
class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        $this->assertSame(5, $settings->maxAttempts);
        $this->assertSame(300, $settings->attemptWindowSeconds);
        $this->assertSame(0, $settings->unlockDurationSeconds);
        $this->assertSame('', $settings->template);
        $this->assertSame('', $settings->customCss);
        $this->assertTrue($settings->bypassForCpUsers);
        $this->assertTrue($settings->validate());
    }

    #[DataProvider('negativeIntegerSettings')]
    public function testIntegerSettingsCannotBeNegative(string $attribute): void
    {
        $settings = new Settings();
        $settings->$attribute = -1;

        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey($attribute, $settings->getErrors());
    }

    public static function negativeIntegerSettings(): array
    {
        return [
            ['maxAttempts'],
            ['attemptWindowSeconds'],
            ['unlockDurationSeconds'],
        ];
    }

    public function testZeroIsAllowedForIntegerSettings(): void
    {
        $settings = new Settings();
        $settings->maxAttempts = 0;
        $settings->attemptWindowSeconds = 0;
        $settings->unlockDurationSeconds = 0;

        $this->assertTrue($settings->validate());
    }

    public function testSafeCustomCssNeutralisesAStyleTagBreakout(): void
    {
        $settings = new Settings();
        $settings->customCss = 'body::after { content: "</style><script>alert(1)</script>" }';

        $safe = $settings->getSafeCustomCss();

        $this->assertStringNotContainsString('<', $safe);
        $this->assertStringContainsString('\\00003c /style>', $safe);
    }

    public function testSafeCustomCssLeavesOrdinaryCssIntact(): void
    {
        $settings = new Settings();
        $settings->customCss = ':root { --sesame-bg: #fff; }';

        $this->assertSame(':root { --sesame-bg: #fff; }', $settings->getSafeCustomCss());
    }

    public function testDefaultCssDefinesTheVariablesTheUnlockTemplateUses(): void
    {
        $template = file_get_contents(dirname(__DIR__, 2) . '/src/templates/_unlock.twig');
        $this->assertIsString($template);

        preg_match_all('/var\(\s*(--sesame-[a-z0-9-]+)/i', $template, $matches);
        $used = array_unique($matches[1]);
        $this->assertNotEmpty($used, 'No CSS variables found in the unlock template');

        foreach ($used as $variable) {
            $this->assertStringContainsString("$variable:", Settings::DEFAULT_CSS,
                "$variable is used by the unlock template but has no default");
        }
    }
}

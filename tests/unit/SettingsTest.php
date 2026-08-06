<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use bensomething\speakeasy\models\Settings;
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

    public function testZeroIsAllowedWhereItMeansOff(): void
    {
        $settings = new Settings();
        $settings->maxAttempts = 0;
        $settings->unlockDurationSeconds = 0;

        $this->assertTrue($settings->validate());
    }

    /**
     * The window is the counter's cache TTL, and Yii reads a TTL of 0 as "never
     * expire", so a visitor who hit the limit would be locked out until the cache
     * was flushed by hand. Rate limiting is turned off with maxAttempts instead.
     */
    public function testZeroIsRejectedForTheLockoutWindow(): void
    {
        $settings = new Settings();
        $settings->attemptWindowSeconds = 0;

        $this->assertFalse($settings->validate());
        $this->assertArrayHasKey('attemptWindowSeconds', $settings->getErrors());
    }

    /**
     * Yii's default would name the attribute ("Attempt Window Seconds must be no
     * less than 1"), which is neither the label on the settings screen nor advice
     * anyone can act on.
     */
    public function testTheLockoutWindowErrorNamesTheSettingAndTheWayOut(): void
    {
        $settings = new Settings();
        $settings->attemptWindowSeconds = 0;
        $settings->validate();

        $error = $settings->getFirstError('attemptWindowSeconds');

        $this->assertStringContainsString('Lockout window', (string) $error);
        $this->assertStringContainsString('Max unlock attempts', (string) $error);
    }

    public function testSettingsAreLabelledAsTheSettingsScreenNamesThem(): void
    {
        $this->assertSame('Lockout window', (new Settings())->getAttributeLabel('attemptWindowSeconds'));
    }

    #[DataProvider('textSettings')]
    public function testWhitespaceOnlyTextSettingsValidateToEmpty(string $attribute): void
    {
        $settings = new Settings();
        $settings->$attribute = "  \t\n ";

        $this->assertTrue($settings->validate());
        $this->assertSame('', $settings->$attribute);
    }

    public static function textSettings(): array
    {
        return [
            ['template'],
            ['customCss'],
            ['placeholderText'],
            ['buttonText'],
            ['errorText'],
            ['lockdownText'],
        ];
    }

    public function testCustomErrorTextIsUsedForTheBundledScreen(): void
    {
        $settings = new Settings();
        $settings->errorText = 'Wrong password, try again';

        $this->assertSame('Wrong password, try again', $settings->getCustomErrorText());
    }

    public function testCustomErrorTextIsNullWhenBlank(): void
    {
        $this->assertNull((new Settings())->getCustomErrorText());
    }

    public function testCustomErrorTextIsIgnoredWhenACustomTemplateIsSet(): void
    {
        // The field is hidden in the CP once a template is set, so a value left
        // over from before must not stay live where it can't be edited.
        $settings = new Settings();
        $settings->errorText = 'Wrong password, try again';
        $settings->template = '_unlock';

        $this->assertNull($settings->getCustomErrorText());
    }

    public function testCustomLockdownTextIsUsedForTheBundledScreen(): void
    {
        $settings = new Settings();
        $settings->lockdownText = 'Back soon';

        $this->assertSame('Back soon', $settings->getCustomLockdownText());
    }

    public function testCustomLockdownTextIsNullWhenBlank(): void
    {
        $this->assertNull((new Settings())->getCustomLockdownText());
    }

    public function testCustomLockdownTextIsIgnoredWhenACustomTemplateIsSet(): void
    {
        $settings = new Settings();
        $settings->lockdownText = 'Back soon';
        $settings->template = '_unlock';

        $this->assertNull($settings->getCustomLockdownText());
    }

    public function testLockdownIsOffWhenTheEnvironmentSaysNothing(): void
    {
        $this->assertFalse((new Settings())->isLockedDown());
    }

    public function testLockdownIsNotAStoredSetting(): void
    {
        // It's environment-only by design, so a stray `lockdown` key in project
        // config or config/speakeasy.php is ignored rather than locking a site.
        $this->assertArrayNotHasKey('lockdown', (new Settings())->attributes());
    }

    /**
     * @param string $value Raw environment value, as it would be written in .env
     */
    #[DataProvider('lockdownEnvValues')]
    public function testLockdownReadsTheEnvironmentVariable(string $value, bool $expected): void
    {
        $_SERVER[Settings::LOCKDOWN_ENV] = $value;

        try {
            $this->assertSame($expected, (new Settings())->isLockedDown());
        } finally {
            unset($_SERVER[Settings::LOCKDOWN_ENV]);
        }
    }

    public static function lockdownEnvValues(): array
    {
        return [
            // Craft normalises these to real booleans and ints before we see them.
            'true turns it on' => ['true', true],
            'false leaves it off' => ['false', false],
            '1 turns it on' => ['1', true],
            '0 leaves it off' => ['0', false],
            // Lets a shared .env template ship the key blank.
            'empty leaves it off' => ['', false],
            // Fails towards locked rather than open.
            'an unrecognised value counts as on' => ['yes', true],
        ];
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
        $settings->customCss = ':root { --speakeasy-bg: #fff; }';

        $this->assertSame(':root { --speakeasy-bg: #fff; }', $settings->getSafeCustomCss());
    }

    public function testDefaultCssDefinesTheVariablesTheUnlockTemplateUses(): void
    {
        // Scan every unlock template, not just the entry file, so the check
        // survives the styles/form partials being split out or renamed.
        $templates = glob(dirname(__DIR__, 2) . '/src/templates/_unlock*.twig');
        $this->assertNotEmpty($templates, 'No unlock templates found');
        $markup = implode('', array_map('file_get_contents', $templates));

        preg_match_all('/var\(\s*(--speakeasy-[a-z0-9-]+)/i', $markup, $matches);
        $used = array_unique($matches[1]);
        $this->assertNotEmpty($used, 'No CSS variables found in the unlock template');

        foreach ($used as $variable) {
            $this->assertStringContainsString("$variable:", Settings::DEFAULT_CSS,
                "$variable is used by the unlock template but has no default");
        }
    }
}

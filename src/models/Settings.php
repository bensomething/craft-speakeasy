<?php

namespace bensomething\speakeasy\models;

use craft\base\Model;
use craft\helpers\App;

class Settings extends Model
{
    /**
     * Default CSS variables for the bundled unlock screen, and the single source
     * of truth for them. The _unlock template emits this block, and the settings
     * field is seeded with it so editors start from the real values. Anything they
     * change is injected after this block, overriding it.
     */
    public const DEFAULT_CSS = <<<'CSS'
:root {
    color-scheme: light dark;
    --speakeasy-background: #fafafa;
    --speakeasy-input-background: #fff;
    --speakeasy-text: #1a1a1a;
    --speakeasy-placeholder-text: #8a8a8a;
    --speakeasy-input-border: #cbcbcb;
    --speakeasy-input-border-focus: #555;
    --speakeasy-button-background: #1a1a1a;
    --speakeasy-button-text: #fff;
    --speakeasy-button-background-hover: #333;
    --speakeasy-error-text: #c0392b;
    --speakeasy-radius: .375rem;
    --speakeasy-font: system-ui, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

@media (prefers-color-scheme: dark) {
    :root {
        --speakeasy-background: #0d0d0d;
        --speakeasy-input-background: #1a1a1a;
        --speakeasy-text: #e8e8e8;
        --speakeasy-placeholder-text: #777;
        --speakeasy-input-border: #333;
        --speakeasy-input-border-focus: #888;
        --speakeasy-button-background: #e8e8e8;
        --speakeasy-button-text: #0d0d0d;
        --speakeasy-button-background-hover: #fff;
    }
}
CSS;

    public int $maxAttempts = 5;
    public int $attemptWindowSeconds = 300;
    public int $unlockDurationSeconds = 0;
    public string $template = '';
    public string $customCss = '';
    // Empty means "use the bundled default". The default strings live in the
    // templates and the controller, run through t() so they stay translatable;
    // storing them here would freeze that. The settings fields show them as
    // placeholder text so an editor still sees what they're overriding.
    public string $placeholderText = '';
    public string $buttonText = '';
    public string $errorText = '';
    public string $lockdownText = '';
    public bool $bypassForCpUsers = true;

    /**
     * Lockdown is deliberately not a setting. It's operational, per-environment
     * state, and a stored value would live in project config, which is shared
     * across environments and would carry a lockdown from wherever it was set to
     * everywhere else. Keeping it out of the model also means it can never be
     * saved into a state the CP has no control to clear.
     */
    public const LOCKDOWN_ENV = 'SPEAKEASY_LOCKDOWN';

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['maxAttempts', 'unlockDurationSeconds'], 'integer', 'min' => 0],
            // Unlike the other two, 0 isn't "off" here: it's passed to the cache
            // as the counter's TTL, where Yii reads 0 as "never expire", so a
            // visitor who hit the limit would stay locked out until the cache was
            // flushed by hand. Rate limiting is turned off with maxAttempts.
            [['attemptWindowSeconds'], 'integer', 'min' => 1],
            // Trim first, so a field holding only whitespace counts as empty and
            // falls back to its default rather than rendering as blank copy.
            [['template', 'customCss', 'placeholderText', 'buttonText', 'errorText', 'lockdownText'], 'trim'],
            [['template', 'customCss', 'placeholderText', 'buttonText', 'errorText', 'lockdownText'], 'string'],
            [['bypassForCpUsers'], 'boolean'],
        ]);
    }

    /**
     * The configured failed-unlock message, or null when the bundled default
     * should be used. Like the other copy fields this belongs to the bundled
     * screen, so a custom template always gets the default: its field is hidden
     * in the CP, and a stale value would otherwise stay live with no way to edit
     * it. Returns null rather than the default string so the caller can keep
     * that string translatable.
     */
    public function getCustomErrorText(): ?string
    {
        if ($this->template !== '' || $this->errorText === '') {
            return null;
        }

        return $this->errorText;
    }

    /**
     * Whether every protected element is currently closed.
     *
     * Craft normalises "true"/"false" and "1"/"0" to booleans and ints before we
     * see them, so those all behave as written. Unset and empty are both off,
     * which lets a shared .env template ship the key blank. Any other non-empty
     * value counts as on, erring towards locked rather than open.
     */
    public function isLockedDown(): bool
    {
        return (bool)App::env(self::LOCKDOWN_ENV);
    }

    /**
     * The configured lockdown message, or null for the bundled default. Same
     * bundled-screen rule as getCustomErrorText().
     */
    public function getCustomLockdownText(): ?string
    {
        if ($this->template !== '' || $this->lockdownText === '') {
            return null;
        }

        return $this->lockdownText;
    }

    /**
     * Custom CSS for the bundled unlock screen, with any `</style>` breakout
     * neutralised so it can't inject markup into that anonymous page. CSS never
     * needs a literal `<`. Escaping it to its CSS code point renders identically
     * inside `content:` strings while making a closing tag impossible.
     */
    public function getSafeCustomCss(): string
    {
        return str_replace('<', '\\00003c ', $this->customCss);
    }
}

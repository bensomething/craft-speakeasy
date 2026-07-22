<?php

namespace bensomething\speakeasy\models;

use craft\base\Model;

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
    --speakeasy-input-text: #1a1a1a;
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
        --speakeasy-input-text: #e8e8e8;
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
    public bool $bypassForCpUsers = true;

    public function rules(): array
    {
        return [
            [['maxAttempts', 'attemptWindowSeconds', 'unlockDurationSeconds'], 'integer', 'min' => 0],
            // Trim first, so a field holding only whitespace counts as empty and
            // falls back to its default rather than rendering as blank copy.
            [['template', 'customCss', 'placeholderText', 'buttonText', 'errorText'], 'trim'],
            [['template', 'customCss', 'placeholderText', 'buttonText', 'errorText'], 'string'],
            [['bypassForCpUsers'], 'boolean'],
        ];
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

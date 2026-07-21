<?php

namespace bensomething\speakeasy\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Default CSS variables for the bundled unlock screen — the single source of
     * truth for them. The _unlock template emits this block, and the settings field
     * is seeded with it so editors start from the real values. Anything they change
     * is injected after this block, overriding it.
     */
    public const DEFAULT_CSS = <<<'CSS'
:root {
    color-scheme: light dark;
    --speakeasy-bg: #fafafa;
    --speakeasy-fg: #1a1a1a;
    --speakeasy-input-bg: #fff;
    --speakeasy-input-border: #cbcbcb;
    --speakeasy-input-border-focus: #555;
    --speakeasy-button-bg: #1a1a1a;
    --speakeasy-button-fg: #fff;
    --speakeasy-button-bg-hover: #333;
    --speakeasy-error: #c0392b;
    --speakeasy-radius: .375rem;
    --speakeasy-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

@media (prefers-color-scheme: dark) {
    :root {
        --speakeasy-bg: #0d0d0d;
        --speakeasy-fg: #e8e8e8;
        --speakeasy-input-bg: #1a1a1a;
        --speakeasy-input-border: #333;
        --speakeasy-input-border-focus: #888;
        --speakeasy-button-bg: #e8e8e8;
        --speakeasy-button-fg: #0d0d0d;
        --speakeasy-button-bg-hover: #fff;
    }
}
CSS;

    public int $maxAttempts = 5;
    public int $attemptWindowSeconds = 300;
    public int $unlockDurationSeconds = 0;
    public string $template = '';
    public string $customCss = '';
    public bool $bypassForCpUsers = true;

    public function rules(): array
    {
        return [
            [['maxAttempts', 'attemptWindowSeconds', 'unlockDurationSeconds'], 'integer', 'min' => 0],
            [['template', 'customCss'], 'string'],
            [['bypassForCpUsers'], 'boolean'],
        ];
    }

    /**
     * Custom CSS for the bundled unlock screen, with any `</style>` breakout
     * neutralised so it can't inject markup into that anonymous page. CSS never
     * needs a literal `<`; escaping it to its CSS code point renders identically
     * inside `content:` strings while making a closing tag impossible.
     */
    public function getSafeCustomCss(): string
    {
        return str_replace('<', '\\00003c ', $this->customCss);
    }
}

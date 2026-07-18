<?php

namespace bensomething\sesame\models;

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
    --sesame-bg: #fafafa;
    --sesame-fg: #1a1a1a;
    --sesame-input-bg: #fff;
    --sesame-input-border: #cbcbcb;
    --sesame-input-border-focus: #555;
    --sesame-button-bg: #1a1a1a;
    --sesame-button-fg: #fff;
    --sesame-button-bg-hover: #333;
    --sesame-error: #c0392b;
    --sesame-radius: .375rem;
    --sesame-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

@media (prefers-color-scheme: dark) {
    :root {
        --sesame-bg: #0d0d0d;
        --sesame-fg: #e8e8e8;
        --sesame-input-bg: #1a1a1a;
        --sesame-input-border: #333;
        --sesame-input-border-focus: #888;
        --sesame-button-bg: #e8e8e8;
        --sesame-button-fg: #0d0d0d;
        --sesame-button-bg-hover: #fff;
    }
}
CSS;

    public int $maxAttempts = 5;
    public int $attemptWindowSeconds = 300;
    public string $template = '';
    public string $customCss = '';
    public bool $bypassForCpUsers = true;

    public function rules(): array
    {
        return [
            [['maxAttempts', 'attemptWindowSeconds'], 'integer', 'min' => 0],
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

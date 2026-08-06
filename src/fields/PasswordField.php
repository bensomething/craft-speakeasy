<?php

namespace bensomething\speakeasy\fields;

use bensomething\speakeasy\fields\conditions\HasPasswordConditionRule;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\NestedElementInterface;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\models\GqlSchema;
use craft\web\View;

/**
 * Speakeasy Password. A reversibly-encrypted field whose value gates front-end
 * access to the element (see the Gate service).
 *
 * The input is a text field masked with bullets via JS rather than a real
 * password input, so browser password managers never treat it as a login field.
 */
class PasswordField extends Field implements PreviewableFieldInterface
{
    private static bool $jsRegistered = false;

    /**
     * Show the eye toggle to reveal/hide the value. When off, the value is
     * shown as plain text with no toggle.
     */
    public bool $showVisibilityToggle = true;

    public static function displayName(): string
    {
        return Craft::t('speakeasy', 'Password');
    }

    public static function icon(): string
    {
        return 'eye-low-vision';
    }

    /**
     * Only the first Password field in a layout gates the element, so a second
     * instance of the same field could never do anything. Craft takes an already
     * placed field out of the layout designer's list, which stops the mistake
     * being made rather than warning about it after the fact.
     *
     * Two *different* Password fields can still be placed, since Craft has no way
     * to rule that out. inputHtml() warns on the later ones.
     */
    public static function isMultiInstance(): bool
    {
        return false;
    }

    public function getSettingsHtml(): ?string
    {
        $warning = Html::tag('blockquote', Html::tag('p', Craft::t('speakeasy',
            "Speakeasy gates a protected element's own page. Content you output elsewhere (listings, relations, the Element API) is not gated, that's up to your templates."
        )), ['class' => ['note', 'warning']]);

        $toggle = Cp::lightswitchFieldHtml([
            'label' => Craft::t('speakeasy', 'Show visibility toggle'),
            'instructions' => Craft::t('speakeasy', 'Show an eye icon to reveal or hide the password. When off, the password is always shown as plain text.'),
            'id' => 'showVisibilityToggle',
            'name' => 'showVisibilityToggle',
            'on' => $this->showVisibilityToggle,
        ]);

        return $warning . $toggle;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof PasswordValue) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }

        // From the database: encrypted + base64-encoded. Decrypt lazily, since
        // presence checks, the mask and index columns never need the plaintext.
        // If decode/decrypt fails (e.g. the security key changed), keep the raw
        // value so the element stays locked rather than becoming public.
        $stored = $value;

        return new PasswordValue(static function() use ($stored): array {
            $decoded = base64_decode($stored, true);
            if ($decoded === false) {
                return [$stored, false];
            }

            $decrypted = Craft::$app->getSecurity()->decryptByKey($decoded);

            return $decrypted === false ? [$stored, false] : [$decrypted, true];
        });
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof PasswordValue) {
            return $value;
        }

        // An undecryptable value posts as an array so the form can distinguish
        // "the editor set a new password" from "the editor left the unusable one
        // alone", without the stored ciphertext being mistaken for a plaintext
        // password and encrypted a second time. See inputHtml().
        if (is_array($value)) {
            $entered = (string)($value['password'] ?? '');
            if ($entered !== '') {
                return new PasswordValue($entered);
            }

            // The value to keep is read back off the element, never taken from the
            // post. serializeValue() writes an undecryptable value to the database
            // exactly as given, so a posted one would land there as an unencrypted
            // password. Craft normalizes from the request before setting the value,
            // so this is still the value that was loaded from the database.
            $current = $element?->getFieldValue($this->handle);

            return $current instanceof PasswordValue && $current->isUndecryptable() ? $current : null;
        }

        // From the edit form: already plain text.
        return is_string($value) && $value !== '' ? new PasswordValue($value) : null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        // A value that couldn't be decrypted is written back exactly as it was
        // found. Encrypting it under the new key would turn the ciphertext into
        // the element's actual password and lose the warning telling the editor
        // to set a real one, so an unrelated save would quietly make it permanent.
        if ($value instanceof PasswordValue && $value->isUndecryptable()) {
            $stored = $value->revealPassword(new RevealToken());

            return $stored === '' ? null : $stored;
        }

        $plain = $value instanceof PasswordValue ? $value->revealPassword(new RevealToken()) : (is_string($value) ? $value : '');
        if ($plain === '') {
            return null;
        }

        return base64_encode(Craft::$app->getSecurity()->encryptByKey($plain));
    }

    /**
     * Never index the password value.
     */
    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    /**
     * Keep the decrypted value out of the GraphQL schema entirely.
     */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return false;
    }

    /**
     * Static (uneditable) render, e.g. when a field layout condition locks the
     * field for the current user. Craft's default runs inputHtml() through
     * Html::disableInputs(), which disables the eye button and drops its JS while
     * leaving the decrypted value sitting in the hidden real input, exposing the
     * plaintext with no way to reveal it. Render our own masked, value-free copy
     * instead. Read-protection belongs to the field's visibility condition. This
     * only governs editing.
     */
    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        // With the toggle on, the value is meant to stay masked and there is no JS
        // here to reveal it, so show the mask and keep the plaintext out of the DOM
        // entirely (no hidden real input). Masking never decrypts. With the toggle
        // off, the field is configured to always show plain text.
        if ($this->showVisibilityToggle || ($value instanceof PasswordValue && $value->isUndecryptable())) {
            // Undecryptable values are masked whatever the toggle says: showing the
            // ciphertext as plain text would present it as a password someone chose.
            $display = $value instanceof PasswordValue
                ? (string) $value
                : (is_string($value) && $value !== '' ? '••••••••' : '');
        } else {
            $display = $value instanceof PasswordValue
                ? $value->revealPassword(new RevealToken())
                : (is_string($value) ? $value : '');
        }

        return Html::tag('div',
            Html::tag('input', '', [
                'type' => 'text',
                'value' => $display,
                'disabled' => true,
                'autocomplete' => 'off',
                'class' => ['text', 'fullwidth', 'code'],
            ]),
            ['data-speakeasy-field' => true],
        );
    }

    /**
     * Makes the field filterable in element indexes and conditions as a
     * lightswitch: on = has a password set, off = doesn't. Presence only.
     */
    public function getElementConditionRuleType(): array|string|null
    {
        return HasPasswordConditionRule::class;
    }

    /**
     * Element index / card column. Never the plaintext, since a client-side
     * reveal here would mean decrypting every listed element's password into
     * the page DOM. Just a check when a password is set, blank when not.
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof PasswordValue || $value->isEmpty()) {
            return '';
        }

        $label = Craft::t('speakeasy', 'Password set');

        return Html::tag('span', Cp::iconSvg('check'), [
            'class' => 'cp-icon',
            'role' => 'img',
            'title' => $label,
            'aria' => ['label' => $label],
            'style' => ['--icon-size' => '1rem'],
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $this->registerJs();

        // An undecryptable value is ciphertext, not a password anyone chose, so it
        // isn't offered for editing. The field starts empty and posts the array
        // shape instead, which keeps the element gated if the entry is saved before
        // a replacement is set (see normalizeValueFromRequest).
        $undecryptable = $value instanceof PasswordValue && $value->isUndecryptable();

        $current = $undecryptable ? '' : ($value instanceof PasswordValue ? $value->revealPassword(new RevealToken()) : (is_string($value) ? $value : ''));
        $showToggle = $this->showVisibilityToggle && !$inline;
        $revealed = !$this->showVisibilityToggle;

        // The layout designer hides this field from non-gate-able element types, but
        // placement can't be fully prevented (inline field creation, project config).
        // Warn on the actual element edit where the gate can't run. Also warn on a
        // second (or later) Password field in the same layout: only the first one
        // gates the page (see Gate::getPassword), so any extra is inert.
        $warningText = null;
        if ($undecryptable) {
            $warningText = Craft::t('speakeasy',
                "This password can't be read because the security key has changed since it was set. The original can't be recovered, so the element stays locked until you enter a new password here."
            );
        } elseif ($element !== null && !$element::hasUris()) {
            $warningText = Craft::t('speakeasy',
                'This element type has no Craft-rendered URL, setting a password will have no effect.'
            );
        } elseif ($this->isNestedWithoutUrl($element)) {
            $warningText = Craft::t('speakeasy',
                'This entry is nested inside another element and has no URL of its own, so a password here has no effect. Protect the page it appears on instead.'
            );
        } elseif ($this->isRedundantInLayout($element)) {
            $warningText = Craft::t('speakeasy',
                'Only the first Password field in a layout gates the element, this additional field has no effect.'
            );
        }
        $warningId = $this->getInputId() . '-warning';

        // Real value (submitted). Craft namespaces this to fields[handle]. When the
        // stored value can't be decrypted the field posts fields[handle][password]
        // instead, so leaving it alone re-saves the original untouched rather than
        // re-encrypting it (see normalizeValueFromRequest).
        $real = Html::hiddenInput(
            $undecryptable ? "{$this->handle}[password]" : $this->handle,
            $current,
            ['data-speakeasy-real' => true],
        );

        // Display copy, not submitted. Masked with bullets unless revealed.
        $display = Html::tag('input', '', [
            'type' => 'text',
            'id' => $this->getInputId(),
            'value' => $revealed ? $current : str_repeat('•', mb_strlen($current)),
            'data-speakeasy-display' => true,
            'autocomplete' => 'off',
            'autocorrect' => 'off',
            'autocapitalize' => 'off',
            'spellcheck' => 'false',
            'data-lpignore' => 'true',
            'data-1p-ignore' => 'true',
            'aria-describedby' => $warningText !== null ? $warningId : null,
            'disabled' => $inline,
            'class' => ['text', 'fullwidth', 'code'],
            'style' => $showToggle ? ['padding-right' => '1.75rem'] : [],
        ]);

        $eye = Html::tag('span', Cp::iconSvg('eye'), [
            'data-speakeasy-eye' => true,
            'class' => 'cp-icon',
            'style' => ['--icon-size' => '1rem', '--icon-color' => 'var(--gray-400)', 'display' => 'inline-flex'],
        ]);
        $eyeOff = Html::tag('span', Cp::iconSvg('eye-low-vision'), [
            'data-speakeasy-eye-off' => true,
            'class' => 'cp-icon',
            'style' => ['--icon-size' => '1rem', '--icon-color' => 'var(--gray-400)', 'display' => 'none'],
        ]);

        // Nothing to reveal when the field is empty, so the toggle starts hidden and
        // the JS shows it as soon as there's a value (and hides it again when the
        // value is cleared).
        $toggle = !$showToggle ? '' : Html::button($eye . $eyeOff, [
            'type' => 'button',
            'data-speakeasy-toggle' => true,
            'title' => Craft::t('speakeasy', 'Show/hide password'),
            'style' => [
                'display' => $current === '' ? 'none' : 'block',
                'position' => 'absolute',
                'top' => '50%',
                'right' => '6px',
                'transform' => 'translateY(-50%)',
                'background' => 'none',
                'border' => '0',
                'padding' => '4px',
                'cursor' => 'pointer',
                'line-height' => '0',
                '-webkit-user-select' => 'none',
                'user-select' => 'none',
            ],
        ]);

        $field = Html::tag('div', $real . $display . $toggle, [
            'data-speakeasy-field' => true,
            'data-speakeasy-shown' => $revealed ? '1' : '0',
            'style' => ['position' => 'relative'],
        ]);

        if ($warningText === null) {
            return $field;
        }

        // Mirrors the markup and spacing Craft gives a field layout element's warning;
        // the native `.field > .warning` margin can't reach us inside the input container.
        $warning = Html::tag('p',
            Html::tag('span', '', ['class' => 'icon', 'aria-hidden' => 'true']) .
            Html::tag('span', Craft::t('app', 'Warning:') . ' ', ['class' => 'visually-hidden']) .
            Html::tag('span', Html::encode($warningText)),
            [
                'id' => $warningId,
                'class' => ['warning', 'has-icon'],
                'style' => ['margin-block' => '5px 0', 'margin-inline' => '0'],
            ]
        );

        return $field . $warning;
    }

    /**
     * True when the element is nested inside another element (e.g. a Matrix block)
     * and has no URL of its own. The gate only ever runs on the element matched for
     * a front-end URL (Gate::handleBeforeRenderPageTemplate), which a nested element
     * without a URI format is never, so a password on it does nothing. A container
     * field that does give its nested elements a URI format (real pages) is left
     * unflagged, since the gate can protect those.
     */
    private function isNestedWithoutUrl(?ElementInterface $element): bool
    {
        if (!$element instanceof NestedElementInterface || $element->getField() === null) {
            return false;
        }

        try {
            return $element->getUriFormat() === null;
        } catch (\Throwable) {
            // Misconfigured ownership: no usable URL either way.
            return true;
        }
    }

    /**
     * True when an earlier Password field in the element's layout already gates
     * it, which makes this one inert and worth warning about.
     *
     * Emptiness is what decides it, not position: Gate::getPassword() returns the
     * first field with a value *set*, so it passes over an earlier field left
     * blank and this one does gate the element after all.
     */
    private function isRedundantInLayout(?ElementInterface $element): bool
    {
        if ($element === null) {
            return false;
        }

        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return false;
        }

        foreach ($layout->getCustomFields() as $field) {
            if (!$field instanceof self) {
                continue;
            }

            if ($field->handle === $this->handle) {
                return false;
            }

            $value = $element->getFieldValue($field->handle);
            if ($value instanceof PasswordValue && !$value->isEmpty()) {
                return true;
            }
        }

        return false;
    }

    private function registerJs(): void
    {
        if (self::$jsRegistered) {
            return;
        }
        self::$jsRegistered = true;

        $js = <<<'JS'
(function(){
  function wire(field){
    if (field._speakeasyWired) return; field._speakeasyWired = true;
    var real = field.querySelector('[data-speakeasy-real]');
    var disp = field.querySelector('[data-speakeasy-display]');
    var btn = field.querySelector('[data-speakeasy-toggle]');
    var eye = field.querySelector('[data-speakeasy-eye]');
    var eyeOff = field.querySelector('[data-speakeasy-eye-off]');
    var value = real ? real.value : '';
    var shown = field.getAttribute('data-speakeasy-shown') === '1';
    function paint(caret){
      disp.value = shown ? value : '•'.repeat(value.length);
      if (real) real.value = value;
      if (btn) btn.style.display = value.length ? 'block' : 'none';
      if (caret != null){ try { disp.setSelectionRange(caret, caret); } catch(e){} }
    }
    disp.addEventListener('beforeinput', function(e){
      var t = e.inputType, s = disp.selectionStart, en = disp.selectionEnd;
      if (t === 'insertText' && e.data != null){
        value = value.slice(0, s) + e.data + value.slice(en);
        e.preventDefault(); paint(s + e.data.length);
      } else if (t === 'deleteContentBackward'){
        if (s !== en){ value = value.slice(0, s) + value.slice(en); e.preventDefault(); paint(s); }
        else if (s > 0){ value = value.slice(0, s - 1) + value.slice(s); e.preventDefault(); paint(s - 1); }
        else { e.preventDefault(); }
      } else if (t === 'deleteContentForward'){
        if (s !== en){ value = value.slice(0, s) + value.slice(en); }
        else { value = value.slice(0, s) + value.slice(s + 1); }
        e.preventDefault(); paint(s);
      } else if (t.indexOf('insert') === 0){
        e.preventDefault();
      }
    });
    disp.addEventListener('paste', function(e){
      e.preventDefault();
      var text = ((e.clipboardData || window.clipboardData).getData('text') || '');
      var s = disp.selectionStart, en = disp.selectionEnd;
      value = value.slice(0, s) + text + value.slice(en);
      paint(s + text.length);
    });
    disp.addEventListener('cut', function(e){
      var s = disp.selectionStart, en = disp.selectionEnd;
      if (s === en) return;
      e.preventDefault();
      try { (e.clipboardData || window.clipboardData).setData('text', shown ? value.slice(s, en) : ''); } catch(_){}
      value = value.slice(0, s) + value.slice(en); paint(s);
    });
    if (btn){
      btn.addEventListener('click', function(){
        shown = !shown;
        if (eye) eye.style.display = shown ? 'none' : 'inline-flex';
        if (eyeOff) eyeOff.style.display = shown ? 'inline-flex' : 'none';
        paint(null);
      });
    }
    paint(null);
  }
  document.querySelectorAll('[data-speakeasy-field]').forEach(wire);
})();
JS;

        Craft::$app->getView()->registerJs($js, View::POS_END);
    }
}

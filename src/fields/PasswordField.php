<?php

namespace bensomething\sesame\fields;

use bensomething\sesame\fields\conditions\HasPasswordConditionRule;
use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\models\GqlSchema;
use craft\web\View;

/**
 * Sesame Password — a reversibly-encrypted field whose value gates front-end
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
        return Craft::t('sesame', 'Password');
    }

    public static function icon(): string
    {
        return 'eye-low-vision';
    }

    public function getSettingsHtml(): ?string
    {
        $warning = Html::tag('blockquote', Html::tag('p', Craft::t('sesame',
            "Sesame gates a protected element's own page. Content you output elsewhere — listings, relations, the Element API — is not gated; that's up to your templates."
        )), ['class' => ['note', 'warning']]);

        $toggle = Cp::lightswitchFieldHtml([
            'label' => Craft::t('sesame', 'Show visibility toggle'),
            'instructions' => Craft::t('sesame', 'Show an eye icon to reveal or hide the password. When off, the password is always shown as plain text.'),
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

        // From the database: encrypted + base64-encoded. Decrypt lazily — presence
        // checks, the mask and index columns never need the plaintext, so only a
        // guarded revealPassword() pays for it. If decode/decrypt fails (e.g. the
        // security key changed), keep the raw value so the element stays locked
        // rather than becoming public.
        $stored = $value;

        return new PasswordValue(static function() use ($stored): string {
            $decoded = base64_decode($stored, true);
            if ($decoded === false) {
                return $stored;
            }

            $decrypted = Craft::$app->getSecurity()->decryptByKey($decoded);

            return $decrypted === false ? $stored : $decrypted;
        });
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof PasswordValue) {
            return $value;
        }

        // From the edit form: already plain text.
        return is_string($value) && $value !== '' ? new PasswordValue($value) : null;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
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
     * Static (uneditable) render — e.g. when a field layout condition locks the
     * field for the current user. Craft's default runs inputHtml() through
     * Html::disableInputs(), which disables the eye button and drops its JS while
     * leaving the decrypted value sitting in the hidden real input. That exposes
     * the plaintext with no way to reveal it — the worst of both. Render our own
     * masked, value-free copy instead. Read-protection belongs to the field's
     * visibility condition; this only governs editing.
     */
    public function getStaticHtml(mixed $value, ElementInterface $element): string
    {
        $plain = $value instanceof PasswordValue ? $value->revealPassword(new RevealToken()) : (is_string($value) ? $value : '');

        // With the toggle on, the value is meant to stay masked and there is no JS
        // here to reveal it — so show the mask and keep the plaintext out of the DOM
        // entirely (no hidden real input). With the toggle off, the field is
        // configured to always show plain text.
        $masked = $value instanceof PasswordValue ? (string) $value : ($plain === '' ? '' : '••••••••');
        $display = $this->showVisibilityToggle ? $masked : $plain;

        return Html::tag('div',
            Html::tag('input', '', [
                'type' => 'text',
                'value' => $display,
                'disabled' => true,
                'autocomplete' => 'off',
                'class' => ['text', 'fullwidth'],
            ]),
            ['data-sesame-field' => true],
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
     * Element index / card column. Never the plaintext — a client-side reveal
     * here would mean decrypting every listed element's password into the page
     * DOM. Just a check when a password is set, blank when not.
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof PasswordValue || $value->isEmpty()) {
            return '';
        }

        $label = Craft::t('sesame', 'Password set');

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

        $current = $value instanceof PasswordValue ? $value->revealPassword(new RevealToken()) : (is_string($value) ? $value : '');
        $showToggle = $this->showVisibilityToggle && !$inline;
        $revealed = !$this->showVisibilityToggle;

        // The layout designer hides this field from non-gate-able element types, but
        // placement can't be fully prevented (inline field creation, project config).
        // Warn on the actual element edit where the gate can't run. Also warn on a
        // second (or later) Password field in the same layout: only the first one
        // gates the page (see Gate::getPassword), so any extra is inert.
        $warningText = null;
        if ($element !== null && !$element::hasUris()) {
            $warningText = Craft::t('sesame',
                'This element type has no Craft-rendered URL, setting a password will have no effect.'
            );
        } elseif ($this->isRedundantInLayout($element)) {
            $warningText = Craft::t('sesame',
                'Only the first Password field in a layout gates the element, this additional field has no effect.'
            );
        }
        $warningId = $this->getInputId() . '-warning';

        // Real value (submitted). Craft namespaces this to fields[handle].
        $real = Html::hiddenInput($this->handle, $current, ['data-sesame-real' => true]);

        // Display copy (not submitted); masked with bullets unless revealed.
        $display = Html::tag('input', '', [
            'type' => 'text',
            'id' => $this->getInputId(),
            'value' => $revealed ? $current : str_repeat('•', mb_strlen($current)),
            'data-sesame-display' => true,
            'autocomplete' => 'off',
            'autocorrect' => 'off',
            'autocapitalize' => 'off',
            'spellcheck' => 'false',
            'data-lpignore' => 'true',
            'data-1p-ignore' => 'true',
            'aria-describedby' => $warningText !== null ? $warningId : null,
            'disabled' => $inline,
            'class' => ['text', 'fullwidth'],
            'style' => $showToggle ? ['padding-right' => '1.75rem'] : [],
        ]);

        $eye = Html::tag('span', Cp::iconSvg('eye'), [
            'data-sesame-eye' => true,
            'class' => 'cp-icon',
            'style' => ['--icon-size' => '1rem', '--icon-color' => 'var(--gray-400)', 'display' => 'inline-flex'],
        ]);
        $eyeOff = Html::tag('span', Cp::iconSvg('eye-low-vision'), [
            'data-sesame-eye-off' => true,
            'class' => 'cp-icon',
            'style' => ['--icon-size' => '1rem', '--icon-color' => 'var(--gray-400)', 'display' => 'none'],
        ]);

        $toggle = !$showToggle ? '' : Html::button($eye . $eyeOff, [
            'type' => 'button',
            'data-sesame-toggle' => true,
            'title' => Craft::t('sesame', 'Show/hide password'),
            'style' => [
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
            'data-sesame-field' => true,
            'data-sesame-shown' => $revealed ? '1' : '0',
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
     * True when this isn't the first Password field in the element's layout. Only
     * the first one gates the element (Gate::getPassword returns the first set),
     * so any later Password field is inert and worth warning about.
     */
    private function isRedundantInLayout(?ElementInterface $element): bool
    {
        $layout = $element?->getFieldLayout();
        if ($layout === null) {
            return false;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof self) {
                return $field->handle !== $this->handle;
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
    if (field._sesameWired) return; field._sesameWired = true;
    var real = field.querySelector('[data-sesame-real]');
    var disp = field.querySelector('[data-sesame-display]');
    var btn = field.querySelector('[data-sesame-toggle]');
    var eye = field.querySelector('[data-sesame-eye]');
    var eyeOff = field.querySelector('[data-sesame-eye-off]');
    var value = real ? real.value : '';
    var shown = field.getAttribute('data-sesame-shown') === '1';
    function paint(caret){
      disp.value = shown ? value : '•'.repeat(value.length);
      if (real) real.value = value;
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
  document.querySelectorAll('[data-sesame-field]').forEach(wire);
})();
JS;

        Craft::$app->getView()->registerJs($js, View::POS_END);
    }
}

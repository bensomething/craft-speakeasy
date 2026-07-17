<?php

namespace bensomething\sesame\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
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
class PasswordField extends Field
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

        // From the database: encrypted + base64-encoded. Decrypt to plain text.
        // If decode/decrypt fails (e.g. the security key changed), keep the raw
        // value so the element stays locked rather than becoming public.
        $decoded = base64_decode($value, true);
        $plain = $decoded === false ? $value : (Craft::$app->getSecurity()->decryptByKey($decoded) ?: $value);

        return new PasswordValue($plain);
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
        $plain = $value instanceof PasswordValue ? $value->revealPassword() : (is_string($value) ? $value : '');
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

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $this->registerJs();

        $current = $value instanceof PasswordValue ? $value->revealPassword() : (is_string($value) ? $value : '');
        $showToggle = $this->showVisibilityToggle && !$inline;
        $revealed = !$this->showVisibilityToggle;

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

        // The layout designer hides this field from non-gate-able element types,
        // but placement can't be fully prevented (inline field creation, project
        // config). Warn on the actual element edit where the gate can't run.
        if ($element !== null && !$element::hasUris()) {
            $warning = Html::tag('blockquote', Html::tag('p', Craft::t('sesame',
                "This element type has no Craft-rendered URL, so Sesame can't gate it — setting a password here has no effect."
            )), ['class' => ['note', 'warning'], 'style' => ['margin-top' => '0']]);

            return $warning . $field;
        }

        return $field;
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

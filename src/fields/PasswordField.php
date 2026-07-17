<?php

namespace bensomething\sesame\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
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
        return Cp::lightswitchFieldHtml([
            'label' => Craft::t('sesame', 'Show visibility toggle'),
            'instructions' => Craft::t('sesame', 'Show an eye icon to reveal or hide the password. When off, the password is always shown as plain text.'),
            'id' => 'showVisibilityToggle',
            'name' => 'showVisibilityToggle',
            'on' => $this->showVisibilityToggle,
        ]);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        // From the database: encrypted + base64-encoded. Decrypt to plain text.
        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return $value;
        }

        $decrypted = Craft::$app->getSecurity()->decryptByKey($decoded);
        return $decrypted === false ? $value : $decrypted;
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        // From the edit form: already plain text.
        return is_string($value) && $value === '' ? null : $value;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return base64_encode(Craft::$app->getSecurity()->encryptByKey($value));
    }

    /**
     * Never index the password value.
     */
    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        return '';
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $this->registerJs();

        $current = is_string($value) ? $value : '';
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

        return Html::tag('div', $real . $display . $toggle, [
            'data-sesame-field' => true,
            'data-sesame-shown' => $revealed ? '1' : '0',
            'style' => ['position' => 'relative'],
        ]);
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

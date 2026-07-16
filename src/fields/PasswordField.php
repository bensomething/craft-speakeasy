<?php

namespace bensomething\sesame\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\web\View;

/**
 * Sesame Password — an encrypted password field.
 *
 * Stores its value encrypted (base64-encoded) with the project security key.
 * Because the encryption is reversible, the true value can be revealed in the
 * control panel. An element carrying a non-empty value in this field becomes
 * password-protected on the front end (see the Gate service).
 *
 * The input is a plain text field masked with bullets via JS (no type=password,
 * no -webkit-text-security), so browser password managers never treat it as a
 * login field.
 */
class PasswordField extends Field
{
    private static bool $jsRegistered = false;

    public static function displayName(): string
    {
        return Craft::t('sesame', 'Sesame Password');
    }

    public static function icon(): string
    {
        return 'lock';
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        // From the database: encrypted + base64-encoded.
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
        // Straight from the edit form: already plain text.
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

        // Real value (submitted). Craft namespaces this to fields[handle].
        $real = Html::hiddenInput($this->handle, $current, ['data-sesame-real' => true]);

        // Bullet-only display (not submitted).
        $display = Html::tag('input', '', [
            'type' => 'text',
            'id' => $this->getInputId(),
            'value' => str_repeat('•', mb_strlen($current)),
            'data-sesame-display' => true,
            'class' => ['text', 'fullwidth'],
            'autocomplete' => 'off',
            'autocorrect' => 'off',
            'autocapitalize' => 'off',
            'spellcheck' => 'false',
            'data-lpignore' => 'true',
            'data-1p-ignore' => 'true',
            'disabled' => $inline,
            'style' => ['flex' => '1 1 auto'],
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

        $toggle = $inline ? '' : Html::button($eye . $eyeOff, [
            'type' => 'button',
            'data-sesame-toggle' => true,
            'title' => Craft::t('sesame', 'Show/hide password'),
            'style' => ['background' => 'none', 'border' => '0', 'padding' => '4px', 'cursor' => 'pointer', 'line-height' => '0'],
        ]);

        return Html::tag('div', $real . $display . $toggle, [
            'data-sesame-field' => true,
            'class' => ['flex'],
            'style' => ['gap' => '5px', 'align-items' => 'center'],
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
    var shown = false;
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
        disp.focus(); disp.select();
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

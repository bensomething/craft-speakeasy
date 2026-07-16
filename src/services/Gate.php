<?php

namespace bensomething\sesame\services;

use bensomething\sesame\fields\PasswordField;
use bensomething\sesame\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\events\TemplateEvent;
use craft\web\View;
use yii\base\Component;

/**
 * The front-end gate: decides whether a matched element is protected, whether
 * the current visitor may pass, and otherwise swaps in the unlock template.
 *
 * Element-agnostic — works for any element type that has a Sesame Password
 * field on its layout and a URL.
 */
class Gate extends Component
{
    /**
     * Session key holding the set of unlocked password tokens (sha256 of each
     * password entered this session). Unlocking is keyed by password, not
     * element, so elements sharing a password unlock together.
     */
    public const SESSION_KEY = 'sesame.unlocked';

    /**
     * Returns the decrypted password protecting an element, or null if it has
     * no Sesame field with a value.
     */
    public function getPassword(ElementInterface $element): ?string
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof PasswordField) {
                $value = $element->getFieldValue($field->handle);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    public function isUnlocked(string $password): bool
    {
        $tokens = Craft::$app->getSession()->get(self::SESSION_KEY, []);
        return in_array($this->token($password), $tokens, true);
    }

    public function unlock(string $password): void
    {
        $session = Craft::$app->getSession();
        $tokens = $session->get(self::SESSION_KEY, []);
        $token = $this->token($password);
        if (!in_array($token, $tokens, true)) {
            $tokens[] = $token;
            $session->set(self::SESSION_KEY, $tokens);
        }
    }

    private function token(string $password): string
    {
        return hash('sha256', $password);
    }

    /**
     * If the matched element is protected and the visitor isn't allowed through,
     * swap the template for the unlock screen. Always marks protected responses
     * as uncacheable and non-indexable.
     */
    public function handleBeforeRenderPageTemplate(TemplateEvent $event): void
    {
        if ($event->templateMode !== View::TEMPLATE_MODE_SITE) {
            return;
        }

        $element = Craft::$app->getUrlManager()->getMatchedElement();
        if (!$element instanceof ElementInterface) {
            return;
        }

        $password = $this->getPassword($element);
        if ($password === null) {
            return;
        }

        // Protected content must never be stored by a page/CDN cache, or indexed.
        $response = Craft::$app->getResponse();
        $response->setNoCacheHeaders();
        $response->headers->set('X-Robots-Tag', 'noindex');

        $settings = Plugin::getInstance()->getSettings();
        $user = Craft::$app->getUser()->getIdentity();

        if (
            $this->isUnlocked($password) ||
            ($settings->bypassForCpUsers && $user !== null && $element->canView($user))
        ) {
            return;
        }

        $event->template = $settings->template ?: 'sesame/_unlock';
        $event->variables = array_merge($event->variables, [
            'element' => $element,
        ]);
    }
}

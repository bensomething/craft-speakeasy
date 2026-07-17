<?php

namespace bensomething\sesame\services;

use bensomething\sesame\fields\PasswordField;
use bensomething\sesame\fields\PasswordValue;
use bensomething\sesame\fields\RevealToken;
use bensomething\sesame\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\events\TemplateEvent;
use craft\web\View;
use yii\base\Component;

/**
 * Front-end gate: shows the unlock screen for a protected element unless the
 * visitor has already unlocked it (or is a bypassing CP user).
 */
class Gate extends Component
{
    // Unlocking is keyed by password hash, not element, so elements sharing a
    // password unlock together.
    public const SESSION_KEY = 'sesame.unlocked';

    public function getPassword(ElementInterface $element): ?string
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof PasswordField) {
                $value = $element->getFieldValue($field->handle);
                if ($value instanceof PasswordValue && !$value->isEmpty()) {
                    return $value->revealPassword(new RevealToken());
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

        // Never cache or index protected content.
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

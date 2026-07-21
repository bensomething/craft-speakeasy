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
    // password unlock together. Values are the unlock timestamp.
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
        $unlockedAt = $this->tokens()[$this->token($password)] ?? null;
        if ($unlockedAt === null) {
            return false;
        }

        $duration = Plugin::getInstance()->getSettings()->unlockDurationSeconds;
        return $duration <= 0 || $unlockedAt + $duration > time();
    }

    public function unlock(string $password): void
    {
        $tokens = $this->tokens();
        $tokens[$this->token($password)] = time();
        Craft::$app->getSession()->set(self::SESSION_KEY, $tokens);
    }

    /**
     * Unlock tokens from the session, dropping anything that isn't a
     * token => timestamp pair (stale data from an older storage format).
     */
    private function tokens(): array
    {
        $tokens = Craft::$app->getSession()->get(self::SESSION_KEY, []);
        if (!is_array($tokens)) {
            return [];
        }

        return array_filter(
            $tokens,
            fn($unlockedAt, $token) => is_string($token) && is_int($unlockedAt),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Keyed with the security key so a leaked session store can't be run
     * against a wordlist to recover the passwords themselves.
     */
    private function token(string $password): string
    {
        return hash_hmac('sha256', $password, Craft::$app->getConfig()->getGeneral()->securityKey);
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

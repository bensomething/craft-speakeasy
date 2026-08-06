<?php

namespace bensomething\speakeasy\services;

use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\fields\RevealToken;
use bensomething\speakeasy\Plugin;
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
    public const SESSION_KEY = 'speakeasy.unlocked';

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
     * against a wordlist to recover the passwords themselves. Public because the
     * unlock action keys its rate-limit counter the same way, and compares
     * tokens rather than passwords (equal length, so no length is leaked).
     */
    public function token(string $value): string
    {
        return hash_hmac('sha256', $value, Craft::$app->getConfig()->getGeneral()->securityKey);
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

        // Checked ahead of lockdown so editors and live preview keep working
        // while the site is closed to the public.
        if ($settings->bypassForCpUsers && $user !== null && $element->canView($user)) {
            return;
        }

        // Lockdown outranks an existing unlock: the session token is left alone
        // but no longer gets anyone in. 403 because this refusal has no remedy,
        // unlike the unlock screen, which is a challenge and stays a 200.
        $lockdown = $settings->isLockedDown();

        if ($lockdown) {
            $response->setStatusCode(403);
        } elseif ($this->isUnlocked($password)) {
            return;
        }

        $event->template = $settings->template ?: 'speakeasy/_unlock';
        $event->variables = array_merge($event->variables, [
            'element' => $element,
            // Custom templates get this so they can render their own locked
            // state. The gate doesn't rely on them honouring it, the unlock
            // action refuses to run under lockdown either way.
            'lockdown' => $lockdown,
        ]);
    }
}

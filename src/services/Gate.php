<?php

namespace bensomething\speakeasy\services;

use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\fields\PasswordValue;
use bensomething\speakeasy\fields\RevealToken;
use bensomething\speakeasy\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\errors\InvalidHtmlTagException;
use craft\events\DefineElementHtmlEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;
use craft\helpers\Html;
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
        return $this->passwordValue($element)?->revealPassword(new RevealToken());
    }

    /**
     * Whether a Password field on the element holds a value, without asking what
     * it is. A stored value decrypts lazily and this never reveals it, so nothing
     * is decrypted: cheap enough to call per element while rendering an index.
     */
    public function isProtected(ElementInterface $element): bool
    {
        return $this->passwordValue($element) !== null;
    }

    /**
     * The first Password field in the element's layout with a value set. Later
     * ones are passed over, so an earlier field left blank doesn't stop a later
     * one gating the element.
     */
    private function passwordValue(ElementInterface $element): ?PasswordValue
    {
        $layout = $element->getFieldLayout();
        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            if ($field instanceof PasswordField) {
                $value = $element->getFieldValue($field->handle);
                if ($value instanceof PasswordValue && !$value->isEmpty()) {
                    return $value;
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
        $session = Craft::$app->getSession();

        // Granting access to a session id that was already in play is what makes
        // fixation work: Craft doesn't enable Yii's useStrictMode, so a session id
        // planted by an attacker (a sibling subdomain can set the cookie) is
        // accepted as-is, and replaying it after a visitor unlocks would put the
        // attacker inside the gate. Craft's own User::login() regenerates for the
        // same reason. Existing tokens are carried over, so unlocking one element
        // doesn't drop the others.
        $tokens = $this->tokens();
        $session->regenerateID(true);

        $tokens[$this->token($password)] = time();
        $session->set(self::SESSION_KEY, $tokens);
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

    /**
     * Adds a padlock to a protected element's chip or card. Craft builds these
     * for indexes, relation fields and element selects, and only `EntryType`
     * implements `Indicative`, so elements have no indicator hook of their own
     * and the icon is appended to the rendered markup instead.
     *
     * Mirrors the `.indicators` markup `Cp::chipHtml()` emits for components that
     * do implement it, so it inherits the same sizing and spacing.
     */
    public function handleDefineElementHtml(DefineElementHtmlEvent $event): void
    {
        if (!Plugin::getInstance()->getSettings()->showLockIcon) {
            return;
        }

        $element = $event->element;
        if (!$element instanceof ElementInterface || !$this->isProtected($element)) {
            return;
        }

        $label = Craft::t('speakeasy', 'Password protected');

        $indicator = Html::tag('div',
            Html::tag('div', Cp::iconSvg('lock'), [
                'class' => ['cp-icon', 'puny'],
                'title' => $label,
                'aria' => ['label' => $label],
            ]),
            ['class' => 'indicators'],
        );

        $event->html = $this->withIndicator($event->html, $indicator);
    }

    /**
     * Places the indicator beside the element's title. Chips and cards are built
     * differently, so each has its own anchor, and markup matching neither is
     * returned untouched: a decorative icon is better missing than adrift outside
     * the component it belongs to.
     */
    private function withIndicator(string $html, string $indicator): string
    {
        // A card's title sits in `.card-heading`, so the icon goes in as that
        // div's last child. parseTag() finds where its contents end, which no
        // amount of string matching can do reliably once the title is a link.
        $heading = strpos($html, '<div class="card-heading">');
        if ($heading !== false) {
            try {
                $end = Html::parseTag($html, $heading)['htmlEnd'] ?? null;
            } catch (InvalidHtmlTagException) {
                $end = null;
            }

            return $end !== null ? substr_replace($html, $indicator, $end, 0) : $html;
        }

        // A chip has no title wrapper to sit inside, so the icon goes after the
        // label, as a sibling of the action menu that follows it.
        $actions = '<div class="chip-actions">';

        return str_contains($html, $actions)
            ? str_replace($actions, $indicator . $actions, $html)
            : $html;
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

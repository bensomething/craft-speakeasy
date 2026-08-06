<?php

namespace bensomething\speakeasy\controllers;

use bensomething\speakeasy\Plugin;
use Craft;
use craft\base\Element;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handles unlock-form submissions.
 */
class UnlockController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    // Failed attempts are rate-limited per IP + password.
    public function actionIndex(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getBodyParam('elementId');
        $submitted = (string)$request->getBodyParam('password');

        // getElementById() resolves drafts, revisions and disabled elements: it
        // applies status(null)->drafts(null)->provisionalDrafts(null)->revisions(null)
        // internally. Drafts and revisions keep the canonical element's URI (the
        // URI validator skips them precisely so the clone keeps it), so without
        // this an anonymous caller could walk element ids and read the URL back
        // off the redirect for content that has no public page of its own.
        $element = $elementId
            ? Craft::$app->getElements()->getElementById($elementId, criteria: [
                'drafts' => false,
                'provisionalDrafts' => false,
                'revisions' => false,
            ])
            : null;

        if (
            $element === null ||
            $element->getUrl() === null ||
            $element->getStatus() === Element::STATUS_DISABLED
        ) {
            throw new NotFoundHttpException('Element not found.');
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Refuse before any password work, so lockdown can't be sidestepped by
        // posting straight to this action. Without it a visitor could still bank
        // a valid session token to spend the moment lockdown lifts.
        if ($settings->isLockedDown()) {
            throw new ForbiddenHttpException('Unlocking is disabled.');
        }

        $expected = $plugin->gate->getPassword($element);

        // Counted per password, not per element: one unlock covers every element
        // sharing that password, so keying on the element would hand a fresh
        // budget to each of them (and to each draft and revision, which carry a
        // copy of the field) while the same secret is being guessed. An element
        // with no password has nothing to guess, so it keys on itself. Keyed with
        // the security key rather than hashed, so a leaked cache gives up neither
        // the password nor the client IP.
        $attemptKey = 'speakeasy:attempts:' . $plugin->gate->token(
            'attempt:' . $request->getUserIP() . ':' . ($expected ?? "element:{$element->id}"),
        );

        // Count this attempt before checking the password, so parallel requests
        // can't outrun a non-atomic counter and brute-force past the limit.
        if (
            $settings->maxAttempts > 0 &&
            $this->bumpAttempts($attemptKey, $settings->attemptWindowSeconds) > $settings->maxAttempts
        ) {
            Craft::$app->getSession()->setError(Craft::t('speakeasy', 'Too many attempts. Please try again later.'));
            return $this->redirect($element->getUrl());
        }

        // Compared as tokens rather than raw strings: hash_equals() is constant
        // time for equal-length inputs but returns early on a length mismatch,
        // which would time the password's length out to an anonymous caller.
        if ($expected !== null && hash_equals($plugin->gate->token($expected), $plugin->gate->token($submitted))) {
            Craft::$app->getCache()->delete($attemptKey);
            $plugin->gate->unlock($expected);
            return $this->redirect($element->getUrl());
        }

        $error = $settings->getCustomErrorText() ?? Craft::t('speakeasy', 'Incorrect password');
        Craft::$app->getSession()->setError($error);
        return $this->redirect($element->getUrl());
    }

    /**
     * Atomically increments the attempt counter and returns the new total. The
     * read-modify-write is serialized with a mutex so concurrent attempts each
     * count. If the lock can't be acquired, it fails safe (treats as over limit).
     */
    private function bumpAttempts(string $key, int $ttl): int
    {
        $cache = Craft::$app->getCache();
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 3)) {
            return PHP_INT_MAX;
        }

        try {
            $count = (int)$cache->get($key) + 1;
            $cache->set($key, $count, $ttl);
            return $count;
        } finally {
            $mutex->release($key);
        }
    }
}

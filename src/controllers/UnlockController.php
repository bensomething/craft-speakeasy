<?php

namespace bensomething\sesame\controllers;

use bensomething\sesame\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Handles unlock-form submissions.
 */
class UnlockController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    // Failed attempts are rate-limited per IP + element.
    public function actionIndex(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getBodyParam('elementId');
        $submitted = (string)$request->getBodyParam('password');

        $element = $elementId ? Craft::$app->getElements()->getElementById($elementId) : null;
        if ($element === null || $element->getUrl() === null) {
            throw new NotFoundHttpException('Element not found.');
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $attemptKey = 'sesame:attempts:' . md5($request->getUserIP() . ':' . $element->id);

        // Count this attempt before checking the password, so parallel requests
        // can't outrun a non-atomic counter and brute-force past the limit.
        if (
            $settings->maxAttempts > 0 &&
            $this->bumpAttempts($attemptKey, $settings->attemptWindowSeconds) > $settings->maxAttempts
        ) {
            Craft::$app->getSession()->setError(Craft::t('sesame', 'Too many attempts. Please try again later.'));
            return $this->redirect($element->getUrl());
        }

        $expected = $plugin->gate->getPassword($element);

        if ($expected !== null && hash_equals($expected, $submitted)) {
            Craft::$app->getCache()->delete($attemptKey);
            $plugin->gate->unlock($expected);
            return $this->redirect($element->getUrl());
        }

        Craft::$app->getSession()->setError(Craft::t('sesame', 'Incorrect password.'));
        return $this->redirect($element->getUrl());
    }

    /**
     * Atomically increments the attempt counter and returns the new total. The
     * read-modify-write is serialized with a mutex so concurrent attempts each
     * count; if the lock can't be acquired, it fails safe (treats as over limit).
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

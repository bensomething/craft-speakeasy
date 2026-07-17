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
        $cache = Craft::$app->getCache();
        $attemptKey = 'sesame:attempts:' . md5($request->getUserIP() . ':' . $element->id);

        if ($settings->maxAttempts > 0 && (int)$cache->get($attemptKey) >= $settings->maxAttempts) {
            Craft::$app->getSession()->setError(Craft::t('sesame', 'Too many attempts. Please try again later.'));
            return $this->redirect($element->getUrl());
        }

        $expected = $plugin->gate->getPassword($element);

        if ($expected !== null && hash_equals($expected, $submitted)) {
            $cache->delete($attemptKey);
            $plugin->gate->unlock($expected);
            return $this->redirect($element->getUrl());
        }

        if ($settings->maxAttempts > 0) {
            $cache->set($attemptKey, (int)$cache->get($attemptKey) + 1, $settings->attemptWindowSeconds);
        }

        Craft::$app->getSession()->setError(Craft::t('sesame', 'Incorrect password.'));
        return $this->redirect($element->getUrl());
    }
}

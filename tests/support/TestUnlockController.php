<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

use bensomething\speakeasy\controllers\UnlockController;
use ReflectionClass;
use yii\web\Response;

/**
 * The real unlock action with only its two framework touchpoints overridden.
 *
 * actionIndex() reads everything else off Craft::$app directly, so the logic under
 * test (lockdown refusal, attempt counting, password comparison) runs unmodified.
 * requirePostRequest() and redirect() are the exceptions: they go through the
 * controller's own injected request/response, which Yii resolves from a real
 * application container. Both are recorded here instead so tests can assert on
 * them.
 */
class TestUnlockController extends UnlockController
{
    public bool $requiredPost = false;
    public ?string $redirectedTo = null;

    /**
     * Built without the constructor, which is a Yii controller bootstrap needing
     * a module and container we deliberately don't have.
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    public function requirePostRequest(): void
    {
        $this->requiredPost = true;
    }

    public function redirect($url, $statusCode = 302): Response
    {
        $this->redirectedTo = (string) $url;

        return new Response();
    }
}

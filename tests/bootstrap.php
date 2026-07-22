<?php

declare(strict_types=1);

/**
 * No Craft application is booted and no database is needed. Yii's bootstrap and
 * the global Craft class are loaded (neither is autoloadable) so that classes
 * extending yii\base\* behave normally, e.g. Model validation.
 *
 * Tests that need Craft::$app install a CraftStub in its place. See
 * tests/support/CraftStub.php.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';
require dirname(__DIR__) . '/vendor/craftcms/cms/src/Craft.php';

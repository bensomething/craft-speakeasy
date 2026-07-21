<?php

declare(strict_types=1);

/**
 * These are framework-free unit tests — no Craft application is booted and no
 * database is needed. Yii's bootstrap is loaded (it isn't autoloadable) so that
 * classes extending yii\base\* behave normally, e.g. Model validation.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/vendor/yiisoft/yii2/Yii.php';

<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

use craft\config\GeneralConfig;

class StubConfigService
{
    public function __construct(private GeneralConfig $general)
    {
    }

    public function getGeneral(): GeneralConfig
    {
        return $this->general;
    }

    /**
     * Craft's config models call back into this while being configured.
     */
    public function getLoadingConfigFile(): ?string
    {
        return null;
    }
}

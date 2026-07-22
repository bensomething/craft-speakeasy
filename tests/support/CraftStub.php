<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\support;

use bensomething\speakeasy\models\Settings;
use bensomething\speakeasy\Plugin;
use bensomething\speakeasy\services\Gate;
use Craft;
use craft\config\GeneralConfig;
use craft\services\Security;
use craft\web\Response;
use ReflectionClass;

/**
 * A stand-in for a booted Craft application, enough for the code paths that read
 * Craft::$app but don't touch the database: the security key, the crypto service,
 * the session, the cache and the mutex.
 *
 * `Craft::$app` is an untyped static (yii\BaseYii::$app), so this can be dropped
 * in without a real Application. Anything a test doesn't stub is simply absent,
 * which surfaces as a clear "undefined method" rather than a silent wrong answer.
 */
class CraftStub
{
    public GeneralConfig $general;
    public StubSession $session;
    public StubCache $cache;
    public StubMutex $mutex;
    public StubUser $user;
    public StubUrlManager $urlManager;
    public StubRequest $request;
    public StubElements $elements;
    public StubI18n $i18n;
    public Response $response;

    /** Read by yii\web\Response during init. */
    public string $charset = 'UTF-8';

    /** Read by Craft::t() when resolving the target language. */
    public string $language = 'en-US';

    /** Yii's module registry, which Module::setInstance()/getInstance() read. */
    public array $loadedModules = [];

    private StubConfigService $configService;
    private ?Security $security = null;

    public function __construct(string $securityKey = 'test-security-key')
    {
        // Set after construction: GeneralConfig's array constructor reads back
        // through Craft::$app, which doesn't exist yet at this point.
        $this->general = new GeneralConfig();
        $this->general->securityKey = $securityKey;

        $this->configService = new StubConfigService($this->general);
        $this->session = new StubSession();
        $this->cache = new StubCache();
        $this->mutex = new StubMutex();
        $this->user = new StubUser();
        $this->urlManager = new StubUrlManager();
        $this->request = new StubRequest();
        $this->elements = new StubElements();
        $this->i18n = new StubI18n();

        // Craft's own Response, so the header and status assertions exercise the
        // real setNoCacheHeaders() rather than a reimplementation of it.
        $this->response = new Response();
    }

    /**
     * Installs a fresh stub as Craft::$app along with a Speakeasy plugin instance
     * carrying the given settings, and returns them for the test to drive.
     *
     * @return array{0: self, 1: Settings, 2: Gate}
     */
    public static function install(array $settings = [], string $securityKey = 'test-security-key'): array
    {
        $app = new self($securityKey);
        // Craft::$app is an untyped static at runtime; only the docblock narrows
        // it to a real Application. Substituting a stub is the point of all this.
        // @phpstan-ignore assign.propertyType
        Craft::$app = $app;

        $settingsModel = new Settings();
        foreach ($settings as $name => $value) {
            $settingsModel->$name = $value;
        }

        // Built without the constructor: the real one is a Yii module bootstrap
        // that registers event handlers and wants services we deliberately don't
        // have. The tests only need getSettings() to answer.
        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
        $settingsProp = (new ReflectionClass(\craft\base\Plugin::class))->getProperty('_settings');
        $settingsProp->setValue($plugin, $settingsModel);

        // Registered by hand for the same reason: the constructor that would
        // normally wire up config()'s components never ran. Tests and the code
        // under test then share one gate, so unlocks made by either are visible
        // to the other.
        $gate = new Gate();
        $plugin->set('gate', $gate);

        Plugin::setInstance($plugin);

        return [$app, $settingsModel, $gate];
    }

    public static function uninstall(): void
    {
        // @phpstan-ignore assign.propertyType
        Craft::$app = null;
    }

    public function getConfig(): StubConfigService
    {
        return $this->configService;
    }

    public function getSecurity(): Security
    {
        return $this->security ??= new Security();
    }

    public function getSession(): StubSession
    {
        return $this->session;
    }

    public function getCache(): StubCache
    {
        return $this->cache;
    }

    public function getMutex(): StubMutex
    {
        return $this->mutex;
    }

    public function getUser(): StubUser
    {
        return $this->user;
    }

    public function getUrlManager(): StubUrlManager
    {
        return $this->urlManager;
    }

    public function getRequest(): StubRequest
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function getElements(): StubElements
    {
        return $this->elements;
    }

    public function getI18n(): StubI18n
    {
        return $this->i18n;
    }
}

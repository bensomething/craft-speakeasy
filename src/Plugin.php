<?php

namespace bensomething\speakeasy;

use bensomething\speakeasy\fields\PasswordField;
use bensomething\speakeasy\models\Settings;
use bensomething\speakeasy\services\Gate;
use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\events\DefineFieldLayoutCustomFieldsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\TemplateEvent;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\services\Fields;
use craft\web\Controller;
use craft\web\View;
use yii\base\Event;

/**
 * Speakeasy. Per-element password protection.
 *
 * Add a Speakeasy Password field to the field layout of any element type with
 * template-rendered URLs (entries, categories, …). Once an element has a value
 * in that field, anonymous front-end visitors are shown an unlock screen until
 * they enter the password. The value is encrypted at rest with the project
 * security key.
 *
 * @property-read Gate $gate
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => ['gate' => Gate::class],
        ];
    }

    public function init(): void
    {
        parent::init();

        // The encrypted password field type (works on any element type).
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            static function(RegisterComponentTypesEvent $event) {
                $event->types[] = PasswordField::class;
            }
        );

        // Make the bundled unlock template resolvable as `speakeasy/_unlock`.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            static function(RegisterTemplateRootsEvent $event) {
                $event->roots['speakeasy'] = __DIR__ . '/templates';
            }
        );

        // Only offer the field on element types the gate can actually protect,
        // those with template-rendered URLs. Hide it from assets, users, global
        // sets, etc., where it would imply protection it can't deliver.
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_CUSTOM_FIELDS,
            static function(DefineFieldLayoutCustomFieldsEvent $event) {
                $type = $event->sender->type;
                if ($type === null || !is_subclass_of($type, ElementInterface::class) || $type::hasUris()) {
                    return;
                }

                foreach ($event->fields as $group => $fields) {
                    $event->fields[$group] = array_filter($fields, static fn($field) => !(
                        $field instanceof CustomField && $field->getField() instanceof PasswordField
                    ));
                }
            }
        );

        // Gate protected elements on front-end requests.
        if (Craft::$app->getRequest()->getIsSiteRequest()) {
            Event::on(
                View::class,
                View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
                fn(TemplateEvent $event) => $this->gate->handleBeforeRenderPageTemplate($event),
            );
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Render settings as a full CP page (not the default fragment) so it can declare
     * native tabs via the `tabs` variable, which Craft wires itself, no custom JS.
     * Inputs are namespaced under `settings` to match how Craft's default plugin-
     * settings response posts them.
     */
    public function getSettingsResponse(): mixed
    {
        /** @var Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('speakeasy/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            // Progressive enhancement: if nystudio107/craft-code-editor is present
            // (it ships with the first-party CKEditor plugin, among others), give the
            // CSS field a Monaco editor. Otherwise fall back to a plain textarea. No
            // hard dependency, no Monaco footprint forced on installs that lack it.
            'hasCodeEditor' => class_exists('nystudio107\\codeeditor\\CodeEditor'),
        ]);
    }
}

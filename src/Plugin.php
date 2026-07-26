<?php

namespace b10k\componentguide;

use b10k\componentguide\models\Settings;
use b10k\componentguide\services\ComponentRepository;
use b10k\componentguide\services\ComponentScanner;
use b10k\componentguide\services\PreviewRenderer;
use b10k\componentguide\services\StoryParser;
use b10k\componentguide\services\TwigSnippetGenerator;
use b10k\componentguide\services\TwigStoryLoader;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\services\UserPermissions;
use craft\events\RegisterUserPermissionsEvent;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * Component Guide — a Storybook-style browser for reusable Twig components.
 *
 * @property-read ComponentRepository $repository
 * @property-read ComponentScanner $scanner
 * @property-read StoryParser $storyParser
 * @property-read TwigStoryLoader $twigStoryLoader
 * @property-read PreviewRenderer $previewRenderer
 * @property-read TwigSnippetGenerator $snippetGenerator
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_ACCESS = 'component-guide:access';

    public string $schemaVersion = '0.1.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                // Wired explicitly (closures, not bare class names) so every
                // constructor dependency is guaranteed to be the plugin's own
                // shared instance. Relying on container autowiring for the
                // nullable TwigStoryLoader parameter could silently resolve to
                // null and disable Twig story support.
                'storyParser' => StoryParser::class,
                'twigStoryLoader' => static fn(): TwigStoryLoader => new TwigStoryLoader(
                    self::getInstance()->getStoryParser(),
                ),
                'scanner' => static fn(): ComponentScanner => new ComponentScanner(
                    self::getInstance()->getStoryParser(),
                    self::getInstance()->getTwigStoryLoader(),
                ),
                'repository' => static fn(): ComponentRepository => new ComponentRepository(
                    self::getInstance()->getScanner(),
                ),
                'previewRenderer' => PreviewRenderer::class,
                'snippetGenerator' => TwigSnippetGenerator::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        /** @var Settings $settings */
        $settings = $this->getSettings();
        $this->hasCpSection = $settings->enableCpSection;

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'b10k\\componentguide\\console\\controllers';
        }

        $this->registerPermissions();
        $this->registerCpRoutes();
        $this->registerBlockPicker();
    }

    public function getRepository(): ComponentRepository
    {
        /** @var ComponentRepository $repo */
        $repo = $this->get('repository');
        return $repo;
    }

    public function getScanner(): ComponentScanner
    {
        /** @var ComponentScanner $scanner */
        $scanner = $this->get('scanner');
        return $scanner;
    }

    public function getStoryParser(): StoryParser
    {
        /** @var StoryParser $parser */
        $parser = $this->get('storyParser');
        return $parser;
    }

    public function getTwigStoryLoader(): TwigStoryLoader
    {
        /** @var TwigStoryLoader $loader */
        $loader = $this->get('twigStoryLoader');
        return $loader;
    }

    public function getPreviewRenderer(): PreviewRenderer
    {
        /** @var PreviewRenderer $renderer */
        $renderer = $this->get('previewRenderer');
        return $renderer;
    }

    public function getSnippetGenerator(): TwigSnippetGenerator
    {
        /** @var TwigSnippetGenerator $gen */
        $gen = $this->get('snippetGenerator');
        return $gen;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('component-guide/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
            'overrides' => Craft::$app->getConfig()->getConfigFromFile('component-guide'),
        ]);
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->hasCpSection) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('component-guide', 'Component Guide');
        $item['url'] = 'component-guide';
        return $item;
    }

    private function registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('component-guide', 'Component Guide'),
                    'permissions' => [
                        self::PERMISSION_ACCESS => [
                            'label' => Craft::t('component-guide', 'Access the component guide'),
                        ],
                    ],
                ];
            }
        );
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event): void {
                $event->rules['component-guide'] = 'component-guide/components/index';
                $event->rules['component-guide/components/<componentId:[\w\-]+>'] = 'component-guide/components/view';
                $event->rules['component-guide/components/<componentId:[\w\-]+>/<storyId:[\w\-]+>'] = 'component-guide/components/view';
                $event->rules['component-guide/preview/<componentId:[\w\-]+>/<storyId:[\w\-]+>'] = 'component-guide/preview/render';
                $event->rules['component-guide/picker-map'] = 'component-guide/picker/map';
            }
        );
    }

    /**
     * Loads the Matrix block-picker assets on CP pages for users who can
     * access the guide. The JS enhances Matrix "New Block" menus with a
     * visual gallery of matching components (see web/js/picker.js).
     */
    private function registerBlockPicker(): void
    {
        // Console requests are never CP requests, so one check suffices.
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function (): void {
                if (Craft::$app->getUser()->checkPermission(self::PERMISSION_ACCESS)) {
                    Craft::$app->getView()->registerAssetBundle(assetbundles\PickerAsset::class);
                }
            }
        );
    }
}

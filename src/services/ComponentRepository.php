<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use b10k\componentguide\models\Settings;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;
use Craft;
use yii\base\Component;

/**
 * Single entry point for discovered components.
 *
 * Centralizes access, counting, grouping and searching. Two cache layers:
 *
 *  1. Request memoization — the scan runs at most once per request.
 *  2. Persistent cache (Craft's cache component) — keyed by a filesystem
 *     fingerprint (story-file paths + mtimes), so it invalidates automatically
 *     the moment any story or template changes. Computing the fingerprint only
 *     walks and stats the tree; the expensive part (require + parse of every
 *     story file) is skipped on a hit. Disable via `enableScanCache`.
 */
class ComponentRepository extends Component
{
    /** Persistent cache TTL, seconds. Correctness comes from the fingerprint;
     *  the TTL only bounds how long stale keys linger in the cache backend. */
    private const CACHE_TTL = 3600;

    private const CACHE_KEY_PREFIX = 'component-guide:scan:';

    /** @var ComponentDefinition[]|null */
    private ?array $components = null;

    /** @var array<string, ComponentDefinition> */
    private array $byId = [];

    /** @var ScanError[] */
    private array $errors = [];

    public function __construct(private ComponentScanner $scanner, array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * @return ComponentDefinition[]
     */
    public function getAll(): array
    {
        if ($this->components === null) {
            $this->load();
        }
        return $this->components ?? [];
    }

    /**
     * @return ScanError[] Global (non-component-specific) scan errors.
     */
    public function getErrors(): array
    {
        if ($this->components === null) {
            $this->load();
        }
        return $this->errors;
    }

    public function getById(string $id): ?ComponentDefinition
    {
        $this->getAll();
        return $this->byId[$id] ?? null;
    }

    public function getStory(string $componentId, string $storyId): ?StoryDefinition
    {
        return $this->getById($componentId)?->getStory($storyId);
    }

    public function componentCount(): int
    {
        return count($this->getAll());
    }

    public function storyCount(): int
    {
        return array_sum(array_map(static fn(ComponentDefinition $c) => $c->storyCount(), $this->getAll()));
    }

    public function hasErrors(): bool
    {
        if ($this->getErrors() !== []) {
            return true;
        }
        foreach ($this->getAll() as $component) {
            if ($component->hasErrors()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, ComponentDefinition[]> Components keyed by group, groups sorted.
     */
    public function getGrouped(): array
    {
        $groups = [];
        foreach ($this->getAll() as $component) {
            $groups[$component->effectiveGroup()][] = $component;
        }
        uksort($groups, static fn($a, $b) => strcasecmp($a, $b));
        return $groups;
    }

    /**
     * Forget the memoized scan (e.g. after settings change within a request).
     */
    public function flush(): void
    {
        $this->components = null;
        $this->byId = [];
        $this->errors = [];
    }

    private function load(): void
    {
        $settings = $this->settings();
        $templatesRoot = Craft::$app->getPath()->getSiteTemplatesPath();

        // Stories come in two formats: PHP (the configured suffix) and Twig
        // (the same suffix with .php swapped for .twig, e.g. `.stories.twig`).
        $suffixes = array_unique([
            $settings->storySuffix,
            preg_replace('/\.php$/', '.twig', $settings->storySuffix),
        ]);

        $result = null;
        $cacheKey = null;

        if ($settings->enableScanCache) {
            $fingerprint = $this->scanner->fingerprint($templatesRoot, $settings->componentPath, $suffixes);
            $cacheKey = self::CACHE_KEY_PREFIX . md5(json_encode([
                $templatesRoot,
                $settings->componentPath,
                $suffixes,
                $fingerprint,
            ]));

            $cached = Craft::$app->getCache()->get($cacheKey);
            if (is_array($cached) && isset($cached['components'], $cached['errors'])) {
                $result = $cached;
            }
        }

        if ($result === null) {
            $result = $this->scanner->scan($templatesRoot, $settings->componentPath, $suffixes);

            if ($cacheKey !== null) {
                Craft::$app->getCache()->set($cacheKey, $result, self::CACHE_TTL);
            }
        }

        $this->components = $result['components'];
        $this->errors = $result['errors'];

        $this->byId = [];
        foreach ($this->components as $component) {
            $this->byId[$component->id] = $component;
        }
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        return $settings;
    }
}

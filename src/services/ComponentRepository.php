<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use b10k\componentguide\models\Settings;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;
use Craft;
use yii\base\Component;
use yii\caching\TagDependency;

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

    /**
     * Tag on every cached scan, so the whole set can be dropped at once — by
     * the Utilities → Caches entry, and on uninstall. Without it the only way
     * to clear our data would be Craft's global “clear everything”.
     */
    public const CACHE_TAG = 'component-guide';

    /**
     * Sources whose code determines the *shape and semantics* of a cached scan
     * result (which templates are listed, how groups are named, what the
     * models hold). Their mtimes go into the cache key, so editing the scanner
     * or a parser invalidates stale entries by itself — during development and
     * after a `composer update` alike. A hand-maintained version constant kept
     * getting forgotten; the filesystem already knows.
     */
    private const CACHE_SCHEMA_SOURCES = [
        __DIR__ . '/ComponentScanner.php',
        __DIR__ . '/StoryParser.php',
        __DIR__ . '/TwigStoryLoader.php',
    ];

    /** @var ComponentDefinition[]|null */
    private ?array $components = null;

    /** @var array<string, ComponentDefinition> */
    private array $byId = [];

    /** @var ScanError[] */
    private array $errors = [];

    /** @var array<string, array{label: ?string, description: ?string, marker: string, group?: string}> Marker-file metadata keyed by directory relative to the scan root. */
    private array $groupMeta = [];

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
     * Marker-file metadata (label/description) keyed by group name as used by
     * {@see getGrouped()}: the composed, hierarchy-aware group name of the
     * marker's own folder (e.g. "Components / Cards").
     *
     * @return array<string, array{label: ?string, description: ?string, marker: string, group?: string}>
     */
    public function getGroupMeta(): array
    {
        $this->getAll();

        $byGroup = [];
        foreach ($this->groupMeta as $dir => $meta) {
            $byGroup[$meta['group'] ?? $meta['label'] ?? ($dir === '' ? 'Ungrouped' : $dir)] = $meta;
        }

        return $byGroup;
    }

    /**
     * Number of story-less components discovered through marker files.
     */
    public function undocumentedCount(): int
    {
        return count(array_filter(
            $this->getAll(),
            static fn(ComponentDefinition $c) => !$c->isDocumented,
        ));
    }

    /**
     * Drops every cached scan (all sites, all settings permutations).
     */
    public static function invalidateCache(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
    }

    /**
     * Forget the memoized scan (e.g. after settings change within a request).
     */
    public function flush(): void
    {
        $this->components = null;
        $this->byId = [];
        $this->errors = [];
        $this->groupMeta = [];
    }

    private function load(): void
    {
        $settings = $this->settings();
        $templatesRoot = Craft::$app->getPath()->getSiteTemplatesPath();

        // Stories come in two formats: PHP (the configured suffix) and Twig
        // (see Settings::twigStorySuffix()).
        $suffixes = array_unique([
            $settings->storySuffix,
            $settings->twigStorySuffix(),
        ]);

        $result = null;
        $cacheKey = null;

        if ($settings->enableScanCache) {
            $fingerprint = $this->scanner->fingerprint($templatesRoot, $settings->componentPath, $suffixes);
            $cacheKey = self::CACHE_KEY_PREFIX . md5(json_encode([
                $this->schemaFingerprint(),
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
                Craft::$app->getCache()->set(
                    $cacheKey,
                    $result,
                    self::CACHE_TTL,
                    new TagDependency(['tags' => [self::CACHE_TAG]]),
                );
            }
        }

        $this->components = $result['components'];
        $this->errors = $result['errors'];
        // Cache entries written before marker support lack the key.
        $this->groupMeta = $result['groupMeta'] ?? [];

        $this->byId = [];
        foreach ($this->components as $component) {
            $this->byId[$component->id] = $component;
        }
    }

    /**
     * Cheap token for "the code that produced this result" — see
     * {@see CACHE_SCHEMA_SOURCES}. Memoized per request; three stat() calls.
     */
    private function schemaFingerprint(): string
    {
        static $token = null;

        if ($token === null) {
            $parts = [];
            foreach (self::CACHE_SCHEMA_SOURCES as $file) {
                $parts[] = basename($file) . ':' . (@filemtime($file) ?: 0);
            }
            $token = md5(implode('|', $parts));
        }

        return $token;
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        return $settings;
    }
}

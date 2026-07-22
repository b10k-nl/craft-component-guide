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
 * Centralizes access, counting, grouping and searching, and memoizes the scan
 * for the duration of the request. Structured so a persistent cache can be added
 * later without touching callers.
 */
class ComponentRepository extends Component
{
    /** @var ComponentDefinition[]|null */
    private ?array $components = null;

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
        foreach ($this->getAll() as $component) {
            if ($component->id === $id) {
                return $component;
            }
        }
        return null;
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
        $this->errors = [];
    }

    private function load(): void
    {
        $settings = $this->settings();
        $result = $this->scanner->scan(
            Craft::$app->getPath()->getSiteTemplatesPath(),
            $settings->componentPath,
            $settings->storySuffix,
        );
        $this->components = $result['components'];
        $this->errors = $result['errors'];
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::getInstance()->getSettings();
        return $settings;
    }
}

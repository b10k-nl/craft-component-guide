<?php

namespace b10k\componentguide\models;

/**
 * A discovered component: a Twig template paired with a story file.
 *
 * A pure value object. Absolute paths are stored for internal rendering only and
 * must never be echoed into CP output or URLs.
 */
class ComponentDefinition
{
    /**
     * @param StoryDefinition[] $stories
     * @param ScanError[] $errors Non-fatal problems specific to this component.
     */
    public function __construct(
        /** @var string Deterministic, URL-safe ID. */
        public string $id,
        /** @var string Base name, e.g. "button". */
        public string $name,
        /** @var string Display title. */
        public string $title,
        /** @var string Template path relative to the templates root, e.g. "_components/button/button". */
        public string $templatePath,
        /** @var string Absolute template path — internal use only, never rendered. */
        public string $absoluteTemplatePath,
        /** @var string Directory relative to the component path, "" for the root. */
        public string $relativeDirectory = '',
        public ?string $group = null,
        public ?string $description = null,
        public ?string $status = null,
        /** @var string Story file path relative to the templates root. */
        public string $storyFilePath = '',
        public array $stories = [],
        public array $errors = [],
        /** @var bool False for story-less components discovered via a marker file. */
        public bool $isDocumented = true,
    ) {
    }

    public function storyCount(): int
    {
        return count($this->stories);
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    /**
     * Effective grouping label: explicit meta group, else the relative folder,
     * else "Ungrouped".
     */
    public function effectiveGroup(): string
    {
        if ($this->group !== null && $this->group !== '') {
            return $this->group;
        }

        if ($this->relativeDirectory !== '') {
            return $this->relativeDirectory;
        }

        return 'Ungrouped';
    }

    public function getStory(string $storyId): ?StoryDefinition
    {
        foreach ($this->stories as $story) {
            if ($story->id === $storyId) {
                return $story;
            }
        }

        return null;
    }
}

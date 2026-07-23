<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ScanError;
use b10k\componentguide\models\StoryDefinition;
use yii\base\Component;

/**
 * Loads and normalizes a single PHP story file into typed StoryDefinitions.
 *
 * Supports two documented formats, normalized to the same internal shape:
 *
 *  1. Simple:  ['Primary' => ['label' => 'Save'], ...]
 *  2. Rich:    ['meta' => [...], 'stories' => ['Primary' => ['args' => [...]]]]
 *
 * Story files are trusted project code, but this parser never executes closures
 * or callbacks — it only reads the returned array.
 */
class StoryParser extends Component
{
    /** Reserved keys recognized inside a rich story entry. */
    private const STORY_KEYS = ['args', 'description', 'background', 'viewport', 'tags'];

    /**
     * @return array{meta: array<string, string>, stories: StoryDefinition[], errors: ScanError[]}
     */
    public function parse(string $absolutePath, string $relativeFile): array
    {
        $errors = [];
        $data = $this->loadFile($absolutePath, $relativeFile, $errors);

        if ($data === null) {
            return ['meta' => [], 'stories' => [], 'errors' => $errors];
        }

        return $this->parseData($data, $relativeFile);
    }

    /**
     * Normalizes already-loaded story data — a PHP file's return value or a
     * Twig StoryCollector's payload — into typed StoryDefinitions.
     *
     * @return array{meta: array<string, string>, stories: StoryDefinition[], errors: ScanError[]}
     */
    public function parseData(mixed $data, string $relativeFile): array
    {
        $errors = [];

        if (!is_array($data)) {
            $errors[] = new ScanError(
                ScanError::STORY_FILE_NOT_ARRAY,
                'Story file did not return an array.',
                $relativeFile,
            );
            return ['meta' => [], 'stories' => [], 'errors' => $errors];
        }

        if ($data === []) {
            $errors[] = new ScanError(
                ScanError::EMPTY_STORY_FILE,
                'Story file returned an empty array.',
                $relativeFile,
            );
            return ['meta' => [], 'stories' => [], 'errors' => $errors];
        }

        [$meta, $rawStories] = $this->splitFormat($data);
        $stories = $this->normalizeStories($rawStories, $relativeFile, $errors);

        if ($stories === [] && $errors === []) {
            $errors[] = new ScanError(
                ScanError::EMPTY_STORY_FILE,
                'Story file contains no stories.',
                $relativeFile,
            );
        }

        return ['meta' => $meta, 'stories' => $stories, 'errors' => $errors];
    }

    private function loadFile(string $absolutePath, string $relativeFile, array &$errors): mixed
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            $errors[] = new ScanError(
                ScanError::STORY_FILE_LOAD_ERROR,
                'Story file is missing or unreadable.',
                $relativeFile,
            );
            return null;
        }

        try {
            // Isolated scope: no access to $this or caller locals.
            return (static fn(string $path): mixed => require $path)($absolutePath);
        } catch (\Throwable $e) {
            $errors[] = new ScanError(
                ScanError::STORY_FILE_LOAD_ERROR,
                'Story file threw while loading.',
                $relativeFile,
                details: $e->getMessage(),
            );
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     * @return array{0: array<string, string>, 1: array<mixed>}
     */
    private function splitFormat(array $data): array
    {
        // Rich format is identified by an explicit "stories" array.
        if (isset($data['stories']) && is_array($data['stories'])) {
            $meta = isset($data['meta']) && is_array($data['meta'])
                ? $this->normalizeMeta($data['meta'])
                : [];
            return [$meta, $data['stories']];
        }

        return [[], $data];
    }

    /**
     * @param array<mixed> $meta
     * @return array<string, string>
     */
    private function normalizeMeta(array $meta): array
    {
        $out = [];
        foreach (['title', 'group', 'description', 'status'] as $key) {
            if (isset($meta[$key]) && is_scalar($meta[$key])) {
                $value = trim((string)$meta[$key]);
                if ($value !== '') {
                    $out[$key] = $value;
                }
            }
        }
        return $out;
    }

    /**
     * @param array<mixed> $rawStories
     * @return StoryDefinition[]
     */
    private function normalizeStories(array $rawStories, string $relativeFile, array &$errors): array
    {
        $stories = [];
        $seenIds = [];

        foreach ($rawStories as $name => $definition) {
            $name = trim((string)$name);
            if ($name === '') {
                $errors[] = new ScanError(
                    ScanError::INVALID_STORY_FORMAT,
                    'A story has an empty name.',
                    $relativeFile,
                );
                continue;
            }

            $id = $this->slug($name);
            if (isset($seenIds[$id])) {
                $errors[] = new ScanError(
                    ScanError::DUPLICATE_STORY_ID,
                    sprintf('Duplicate story “%s” (resolves to ID “%s”).', $name, $id),
                    $relativeFile,
                    storyId: $id,
                );
                continue;
            }

            $story = $this->buildStory($id, $name, $definition, $relativeFile, $errors);
            if ($story !== null) {
                $seenIds[$id] = true;
                $stories[] = $story;
            }
        }

        return $stories;
    }

    private function buildStory(string $id, string $name, mixed $definition, string $relativeFile, array &$errors): ?StoryDefinition
    {
        if (!is_array($definition)) {
            $errors[] = new ScanError(
                ScanError::INVALID_STORY_FORMAT,
                sprintf('Story “%s” must be an array of args.', $name),
                $relativeFile,
                storyId: $id,
            );
            return null;
        }

        // Rich entry: has any reserved key. Otherwise the whole array is the args.
        $isRich = array_intersect(self::STORY_KEYS, array_keys($definition)) !== [];

        if ($isRich) {
            $args = $definition['args'] ?? [];
            if (!is_array($args)) {
                $errors[] = new ScanError(
                    ScanError::INVALID_STORY_FORMAT,
                    sprintf('Story “%s” has non-array “args”.', $name),
                    $relativeFile,
                    storyId: $id,
                );
                return null;
            }
            $description = $this->stringOrNull($definition['description'] ?? null);
            $background = $this->stringOrNull($definition['background'] ?? null);
            $viewport = $this->stringOrNull($definition['viewport'] ?? null);
            $tags = $this->normalizeTags($definition['tags'] ?? []);
        } else {
            $args = $definition;
            $description = $background = $viewport = null;
            $tags = [];
        }

        return new StoryDefinition(
            id: $id,
            name: $name,
            title: $name,
            args: $args,
            description: $description,
            background: $background,
            viewport: $viewport,
            tags: $tags,
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_scalar($value)) {
            $value = trim((string)$value);
            return $value === '' ? null : $value;
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = [$tags];
        }
        if (!is_array($tags)) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn($tag) => is_scalar($tag) ? trim((string)$tag) : '',
            $tags
        ), static fn($tag) => $tag !== ''));
    }

    /**
     * Deterministic, URL-safe slug. Kept dependency-free so the parser is unit
     * testable without booting Craft.
     */
    public function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}

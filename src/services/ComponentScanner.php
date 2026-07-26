<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use yii\base\Component;

/**
 * Discovers components by walking the configured directory for story files and
 * pairing each with its Twig template.
 *
 * Pure and Craft-free by design (paths are passed in), so it can be unit tested
 * without booting the CP. One broken component never aborts the whole scan.
 */
class ComponentScanner extends Component
{
    private const IGNORED_DIRS = ['node_modules', 'vendor', 'cache', '.git'];

    public function __construct(
        private StoryParser $storyParser,
        private ?TwigStoryLoader $twigStoryLoader = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * @param string|string[] $storySuffix One or more story-file suffixes
     *        (e.g. '.stories.php', '.stories.twig').
     * @return array{components: ComponentDefinition[], errors: ScanError[]}
     */
    public function scan(string $templatesRoot, string $componentPath, string|array $storySuffix): array
    {
        // Longest suffix first so an overlapping pair can never mis-match.
        $storySuffixes = array_values(array_filter((array)$storySuffix));
        usort($storySuffixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $errors = [];

        $templatesRoot = $this->normalize($templatesRoot);
        $realTemplatesRoot = realpath($templatesRoot);
        $componentDir = $this->normalize(rtrim($templatesRoot, '/') . '/' . trim($componentPath, '/'));
        $realComponentDir = realpath($componentDir);

        if ($realComponentDir === false || !is_dir($realComponentDir)) {
            $errors[] = new ScanError(
                ScanError::MISSING_COMPONENT_DIRECTORY,
                sprintf('Component directory “%s” was not found.', $componentPath),
            );
            return ['components' => [], 'errors' => $errors];
        }

        $realComponentDir = $this->normalize($realComponentDir);

        // Traversal guard: resolved directory must live inside the templates root.
        if ($realTemplatesRoot !== false) {
            $realTemplatesRoot = $this->normalize($realTemplatesRoot);
            if ($realComponentDir !== $realTemplatesRoot && !str_starts_with($realComponentDir . '/', $realTemplatesRoot . '/')) {
                $errors[] = new ScanError(
                    ScanError::INVALID_COMPONENT_DIRECTORY,
                    'The component directory resolves outside the templates folder.',
                );
                return ['components' => [], 'errors' => $errors];
            }
        }

        if (!is_readable($realComponentDir)) {
            $errors[] = new ScanError(
                ScanError::INVALID_COMPONENT_DIRECTORY,
                sprintf('Component directory “%s” is not readable.', $componentPath),
            );
            return ['components' => [], 'errors' => $errors];
        }

        $storyFiles = [];
        $this->collectStoryFiles($realComponentDir, $storySuffixes, $storyFiles, $errors);
        ksort($storyFiles);

        $components = [];
        $seenIds = [];

        foreach ($storyFiles as $storyFile => $matchedSuffix) {
            $component = $this->buildComponent($storyFile, $realComponentDir, $realTemplatesRoot ?: $templatesRoot, $matchedSuffix);
            if ($component === null) {
                continue;
            }

            if (isset($seenIds[$component->id])) {
                $errors[] = new ScanError(
                    ScanError::DUPLICATE_COMPONENT_ID,
                    sprintf('Duplicate component ID “%s”.', $component->id),
                    $component->storyFilePath,
                    componentId: $component->id,
                );
                continue;
            }

            $seenIds[$component->id] = true;
            $components[] = $component;
        }

        usort($components, static function (ComponentDefinition $a, ComponentDefinition $b): int {
            return [strtolower($a->effectiveGroup()), strtolower($a->title)]
                <=> [strtolower($b->effectiveGroup()), strtolower($b->title)];
        });

        return ['components' => $components, 'errors' => $errors];
    }

    /**
     * @param string[] $storySuffixes Sorted longest-first.
     */
    private function matchSuffix(string $file, array $storySuffixes): ?string
    {
        foreach ($storySuffixes as $suffix) {
            if (str_ends_with($file, $suffix)) {
                return $suffix;
            }
        }
        return null;
    }

    /**
     * Cheap change-detection token for the scan inputs: hashes every story
     * file's path + mtime and whether its paired Twig template exists. Walking
     * the tree and stat-ing files is orders of magnitude cheaper than a full
     * scan (which `require`s and parses every story file), so callers can use
     * this as a cache key part and invalidate automatically on any change.
     *
     * @param string|string[] $storySuffix
     */
    public function fingerprint(string $templatesRoot, string $componentPath, string|array $storySuffix): string
    {
        $storySuffixes = array_values(array_filter((array)$storySuffix));
        usort($storySuffixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $componentDir = $this->normalize(rtrim($this->normalize($templatesRoot), '/') . '/' . trim($componentPath, '/'));
        $realComponentDir = realpath($componentDir);

        if ($realComponentDir === false || !is_dir($realComponentDir)) {
            return md5('missing:' . $componentDir . '#' . implode(',', $storySuffixes));
        }

        $files = [];
        $ignored = [];
        $this->collectStoryFiles($this->normalize($realComponentDir), $storySuffixes, $files, $ignored);
        ksort($files);

        $state = [];
        foreach ($files as $path => $suffix) {
            $template = substr($path, 0, -strlen($suffix)) . '.twig';
            $state[] = $path
                . '|' . (@filemtime($path) ?: 0)
                . '|' . (is_file($template) ? (@filemtime($template) ?: 1) : 0);
        }

        return md5(implode("\n", $state) . '#' . implode(',', $storySuffixes));
    }

    /**
     * @param string[] $storySuffixes
     * @param array<string, string> $out Story file path => matched suffix.
     */
    private function collectStoryFiles(string $dir, array $storySuffixes, array &$out, array &$errors): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            $errors[] = new ScanError(
                ScanError::INVALID_COMPONENT_DIRECTORY,
                sprintf('Could not read directory “%s”.', $dir),
            );
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $this->normalize($dir . '/' . $entry);

            if (is_dir($path)) {
                if (in_array(strtolower($entry), self::IGNORED_DIRS, true)) {
                    continue;
                }
                $this->collectStoryFiles($path, $storySuffixes, $out, $errors);
                continue;
            }

            $matchedSuffix = $this->matchSuffix($entry, $storySuffixes);
            if ($matchedSuffix !== null) {
                $out[$path] = $matchedSuffix;
            }
        }
    }

    private function buildComponent(string $storyFile, string $componentDir, string $templatesRoot, string $storySuffix): ?ComponentDefinition
    {
        $dir = dirname($storyFile);
        $base = substr(basename($storyFile), 0, -strlen($storySuffix));

        if ($base === '') {
            return null;
        }

        $templateAbs = $this->normalize($dir . '/' . $base . '.twig');
        $relativeToRoot = $this->relativePath($storyFile, $templatesRoot);
        $templateRelative = $this->relativePath($templateAbs, $templatesRoot);
        $relativeToComponentDir = $this->relativePath($storyFile, $componentDir);

        // Component ID is derived from the template's path relative to the
        // component directory (without extension), so it is deterministic and
        // stable across machines but never leaks an absolute path. A repeated
        // "folder/name" segment (e.g. button/button) collapses to "button".
        $idPath = str_replace('\\', '/', substr($relativeToComponentDir, 0, -strlen($storySuffix)));
        $segments = explode('/', $idPath);
        $count = count($segments);
        if ($count >= 2 && strcasecmp($segments[$count - 1], $segments[$count - 2]) === 0) {
            array_pop($segments);
        }
        $id = $this->slugPath(implode('/', $segments));

        $relativeDirectory = trim(implode('/', array_slice($segments, 0, -1)), '/');

        // Twig stories render through Craft's view; PHP stories are plain includes.
        if (str_ends_with($storyFile, '.twig') && $this->twigStoryLoader !== null) {
            $parsed = $this->twigStoryLoader->load($storyFile, $relativeToRoot);
        } else {
            $parsed = $this->storyParser->parse($storyFile, $relativeToRoot);
        }
        $componentErrors = $parsed['errors'];

        if (!is_file($templateAbs)) {
            $componentErrors[] = new ScanError(
                ScanError::MISSING_TEMPLATE,
                sprintf('No “%s.twig” template found next to the story file.', $base),
                $relativeToRoot,
                componentId: $id,
            );
        }

        $meta = $parsed['meta'];

        return new ComponentDefinition(
            id: $id,
            name: $base,
            title: $meta['title'] ?? $this->humanize($base),
            templatePath: $templateRelative,
            absoluteTemplatePath: $templateAbs,
            relativeDirectory: $relativeDirectory,
            group: $meta['group'] ?? null,
            description: $meta['description'] ?? null,
            status: $meta['status'] ?? null,
            storyFilePath: $relativeToRoot,
            stories: $parsed['stories'],
            errors: $componentErrors,
        );
    }

    private function relativePath(string $path, string $base): string
    {
        $path = $this->normalize($path);
        $base = rtrim($this->normalize($base), '/');
        if (str_starts_with($path, $base . '/')) {
            return substr($path, strlen($base) + 1);
        }
        // Not under the base (shouldn't happen after the realpath guard) —
        // return as-is rather than mangling occurrences of the base mid-path.
        return ltrim($path, '/');
    }

    private function slugPath(string $path): string
    {
        $path = strtolower(str_replace('\\', '/', $path));
        $path = preg_replace('/[^a-z0-9\/]+/', '-', $path) ?? '';
        $path = str_replace('/', '-', $path);
        return trim(preg_replace('/-+/', '-', $path) ?? '', '-');
    }

    private function humanize(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        return ucwords(trim($value));
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/') ?: '/';
    }
}

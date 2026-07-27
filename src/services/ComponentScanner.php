<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use yii\base\Component;

/**
 * Discovers components by walking the configured directory for story files and
 * pairing each with its Twig template. Directories containing a marker file
 * (see {@see MARKER_FILES}) additionally expose every plain Twig template in
 * their subtree as an "undocumented" component — a story file is an upgrade,
 * not the ticket in.
 *
 * Pure and Craft-free by design (paths are passed in), so it can be unit tested
 * without booting the CP. One broken component never aborts the whole scan.
 */
class ComponentScanner extends Component
{
    private const IGNORED_DIRS = ['node_modules', 'vendor', 'cache', '.git'];

    /**
     * Marker files that opt a directory (and its whole subtree) into
     * undocumented-component discovery. Listed in precedence order — when one
     * directory contains several, the first name wins and the rest trigger a
     * non-fatal DUPLICATE_MARKER warning.
     */
    public const MARKER_FILES = ['GUIDE.md', 'BLOCKS.md', 'COMPONENTS.md'];

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
     * @return array{components: ComponentDefinition[], errors: ScanError[], groupMeta: array<string, array{label: ?string, description: ?string, marker: string, group: string}>}
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
            return ['components' => [], 'errors' => $errors, 'groupMeta' => []];
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
                return ['components' => [], 'errors' => $errors, 'groupMeta' => []];
            }
        }

        if (!is_readable($realComponentDir)) {
            $errors[] = new ScanError(
                ScanError::INVALID_COMPONENT_DIRECTORY,
                sprintf('Component directory “%s” is not readable.', $componentPath),
            );
            return ['components' => [], 'errors' => $errors, 'groupMeta' => []];
        }

        $storyFiles = [];
        $twigFiles = [];
        $markers = [];
        $this->collectFiles($realComponentDir, $storySuffixes, $storyFiles, $twigFiles, $markers, $errors);
        ksort($storyFiles);

        // Marker files: resolve each directory's winner by precedence (and
        // surface duplicates as non-fatal warnings) before building the
        // components, so a marker's H1 label can act as the default group for
        // everything in its subtree.
        $rootForRel = $realTemplatesRoot ?: $templatesRoot;
        $groupMeta = [];
        $markerDirs = [];
        ksort($markers);

        foreach ($markers as $markerDir => $markerPaths) {
            $winner = $markerPaths[0];
            $markerDirs[] = $markerDir;

            if (count($markerPaths) > 1) {
                $errors[] = new ScanError(
                    ScanError::DUPLICATE_MARKER,
                    sprintf(
                        'Multiple marker files (%s) in one directory; “%s” takes precedence.',
                        implode(', ', array_map('basename', $markerPaths)),
                        basename($winner),
                    ),
                    $this->relativePath($winner, $rootForRel),
                );
            }

            $meta = $this->parseMarker($winner);
            $groupKey = $markerDir === $realComponentDir ? '' : $this->relativePath($markerDir, $realComponentDir);
            $groupMeta[$groupKey] = [
                'label' => $meta['label'],
                'description' => $meta['description'],
                'marker' => $this->relativePath($winner, $rootForRel),
            ];
        }

        // Marker H1s double as group names, composed per directory so the
        // guide mirrors the folder hierarchy ("Components / Cards"): starting
        // at the shallowest covering marker, each marker's H1 replaces its own
        // folder's name and plain folders keep a humanized version.
        $markerRelKeys = array_keys($groupMeta);
        $markerRelLabels = [];
        foreach ($groupMeta as $groupKey => $meta) {
            if ($meta['label'] !== null) {
                $markerRelLabels[$groupKey] = $meta['label'];
            }
        }
        foreach ($groupMeta as $groupKey => $meta) {
            $groupMeta[$groupKey]['group'] = $this->markerGroup($groupKey, $markerRelKeys, $markerRelLabels)
                ?? ($groupKey === '' ? 'Ungrouped' : $groupKey);
        }

        $components = [];
        $seenIds = [];

        foreach ($storyFiles as $storyFile => $matchedSuffix) {
            $component = $this->buildComponent($storyFile, $realComponentDir, $realTemplatesRoot ?: $templatesRoot, $matchedSuffix);
            if ($component === null) {
                continue;
            }

            // No explicit meta group → derive one from the marker hierarchy,
            // so documented and undocumented cards land in the same section.
            if ($groupMeta !== [] && ($component->group === null || $component->group === '')) {
                $component->group = $this->markerGroup($component->relativeDirectory, $markerRelKeys, $markerRelLabels);
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

        // Undocumented discovery: inside marked subtrees, every plain Twig
        // template without a story becomes a story-less card. Underscore-
        // prefixed files are treated as internal partials and skipped.
        if ($markerDirs !== []) {
            $pairedTemplates = [];
            foreach ($storyFiles as $storyFile => $matchedSuffix) {
                $pairedTemplates[substr($storyFile, 0, -strlen($matchedSuffix)) . '.twig'] = true;
            }

            sort($twigFiles);
            foreach ($twigFiles as $twigFile) {
                $baseName = basename($twigFile);
                if (isset($pairedTemplates[$twigFile])
                    || str_starts_with($baseName, '_')
                    || $this->matchSuffix($baseName, $storySuffixes) !== null
                    || !$this->isCovered(dirname($twigFile), $markerDirs)
                ) {
                    continue;
                }

                $component = $this->buildUndocumented($twigFile, $realComponentDir, $rootForRel);
                if ($component === null || isset($seenIds[$component->id])) {
                    // An ID collision with a documented component means this
                    // template is already in the guide — skip quietly.
                    continue;
                }

                $component->group = $this->markerGroup($component->relativeDirectory, $markerRelKeys, $markerRelLabels);

                $seenIds[$component->id] = true;
                $components[] = $component;
            }
        }

        usort($components, static function (ComponentDefinition $a, ComponentDefinition $b): int {
            return [strtolower($a->effectiveGroup()), strtolower($a->title)]
                <=> [strtolower($b->effectiveGroup()), strtolower($b->title)];
        });

        return ['components' => $components, 'errors' => $errors, 'groupMeta' => $groupMeta];
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
        $twigFiles = [];
        $markers = [];
        $ignored = [];
        $this->collectFiles($this->normalize($realComponentDir), $storySuffixes, $files, $twigFiles, $markers, $ignored);
        ksort($files);

        $state = [];
        foreach ($files as $path => $suffix) {
            $template = substr($path, 0, -strlen($suffix)) . '.twig';
            $state[] = $path
                . '|' . (@filemtime($path) ?: 0)
                . '|' . (is_file($template) ? (@filemtime($template) ?: 1) : 0);
        }

        // Markers drive group labels (content matters) and which plain
        // templates are listed (existence matters), so both feed the state.
        if ($markers !== []) {
            ksort($markers);
            $markerDirs = array_keys($markers);

            foreach ($markers as $markerPaths) {
                foreach ($markerPaths as $markerPath) {
                    $state[] = 'm:' . $markerPath . '|' . (@filemtime($markerPath) ?: 0);
                }
            }

            sort($twigFiles);
            foreach ($twigFiles as $twigFile) {
                if ($this->isCovered(dirname($twigFile), $markerDirs)) {
                    $state[] = 't:' . $twigFile;
                }
            }
        }

        return md5(implode("\n", $state) . '#' . implode(',', $storySuffixes));
    }

    /**
     * Walks the tree once, collecting story files, plain Twig templates and
     * marker files.
     *
     * @param string[] $storySuffixes
     * @param array<string, string> $storyFiles Story file path => matched suffix.
     * @param string[] $twigFiles Plain (non-story) Twig template paths.
     * @param array<string, string[]> $markers Directory => marker paths, in precedence order.
     */
    private function collectFiles(string $dir, array $storySuffixes, array &$storyFiles, array &$twigFiles, array &$markers, array &$errors): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            $errors[] = new ScanError(
                ScanError::INVALID_COMPONENT_DIRECTORY,
                sprintf('Could not read directory “%s”.', $dir),
            );
            return;
        }

        foreach (self::MARKER_FILES as $markerName) {
            if (in_array($markerName, $entries, true) && is_file($dir . '/' . $markerName)) {
                $markers[$dir][] = $this->normalize($dir . '/' . $markerName);
            }
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
                $this->collectFiles($path, $storySuffixes, $storyFiles, $twigFiles, $markers, $errors);
                continue;
            }

            $matchedSuffix = $this->matchSuffix($entry, $storySuffixes);
            if ($matchedSuffix !== null) {
                $storyFiles[$path] = $matchedSuffix;
                continue;
            }

            if (str_ends_with($entry, '.twig')) {
                $twigFiles[] = $path;
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
        [$id, $relativeDirectory] = $this->deriveId($idPath);

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

    /**
     * Derives the component ID and relative directory from a path relative to
     * the component directory (without extension). A repeated "folder/name"
     * segment (e.g. button/button) collapses to "button".
     *
     * @return array{0: string, 1: string} [id, relativeDirectory]
     */
    private function deriveId(string $idPath): array
    {
        $segments = explode('/', str_replace('\\', '/', $idPath));
        $count = count($segments);
        if ($count >= 2 && strcasecmp($segments[$count - 1], $segments[$count - 2]) === 0) {
            array_pop($segments);
        }

        return [
            $this->slugPath(implode('/', $segments)),
            trim(implode('/', array_slice($segments, 0, -1)), '/'),
        ];
    }

    /**
     * Builds a story-less component card for a plain Twig template discovered
     * through a marker file.
     */
    private function buildUndocumented(string $templateAbs, string $componentDir, string $templatesRoot): ?ComponentDefinition
    {
        $base = substr(basename($templateAbs), 0, -strlen('.twig'));
        if ($base === '') {
            return null;
        }

        $relativeToComponentDir = $this->relativePath($templateAbs, $componentDir);
        [$id, $relativeDirectory] = $this->deriveId(substr($relativeToComponentDir, 0, -strlen('.twig')));

        if ($id === '') {
            return null;
        }

        return new ComponentDefinition(
            id: $id,
            name: $base,
            title: $this->humanize($base),
            templatePath: $this->relativePath($templateAbs, $templatesRoot),
            absoluteTemplatePath: $templateAbs,
            relativeDirectory: $relativeDirectory,
            isDocumented: false,
        );
    }

    /**
     * Whether a directory sits inside (or is) one of the marked directories.
     *
     * @param string[] $markerDirs Normalized directory paths.
     */
    private function isCovered(string $dir, array $markerDirs): bool
    {
        foreach ($markerDirs as $markerDir) {
            if ($dir === $markerDir || str_starts_with($dir . '/', $markerDir . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Composes the display group for a directory inside a marked subtree,
     * mirroring the folder hierarchy: starting at the shallowest covering
     * marker, each marker's H1 replaces its own folder's name and plain
     * folders are humanized ("Components / Cards"). Returns null when the
     * directory is not covered by any marker or nothing yields a name.
     *
     * @param string $relDir Directory relative to the scan root ('' for the root).
     * @param string[] $markerKeys All marker directories relative to the scan root.
     * @param array<string, string> $markerLabels Marker directory => H1 label.
     */
    private function markerGroup(string $relDir, array $markerKeys, array $markerLabels): ?string
    {
        $covering = array_filter(
            $markerKeys,
            static fn(string $key): bool => $key === '' || $key === $relDir || str_starts_with($relDir . '/', $key . '/'),
        );

        if ($covering === []) {
            return null;
        }

        // Shallowest covering marker: the root of this branch of the guide.
        $start = null;
        $startDepth = PHP_INT_MAX;
        foreach ($covering as $key) {
            $depth = $key === '' ? 0 : substr_count($key, '/') + 1;
            if ($depth < $startDepth) {
                $startDepth = $depth;
                $start = $key;
            }
        }

        $parts = [];
        if (isset($markerLabels[$start])) {
            $parts[] = $markerLabels[$start];
        }

        $remainder = $relDir === $start ? '' : ($start === '' ? $relDir : substr($relDir, strlen($start) + 1));
        if ($remainder !== '') {
            $key = $start;
            foreach (explode('/', $remainder) as $segment) {
                $key = $key === '' ? $segment : $key . '/' . $segment;
                $parts[] = $markerLabels[$key] ?? $this->humanize($segment);
            }
        }

        return $parts === [] ? null : implode(' / ', $parts);
    }

    /**
     * Extracts the optional H1 (group label) and the intro text below it
     * (group description) from a marker file. Only the text before the next
     * heading is used — anything further down is documentation, not UI copy.
     *
     * @return array{label: ?string, description: ?string}
     */
    private function parseMarker(string $path): array
    {
        $contents = @file_get_contents($path, false, null, 0, 65536);
        if ($contents === false || trim($contents) === '') {
            return ['label' => null, 'description' => null];
        }

        $label = null;
        $body = $contents;

        if (preg_match('/^#[ \t]+(.+?)[ \t]*$/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $label = trim($match[1][0]);
            $body = substr($contents, $match[0][1] + strlen($match[0][0]));
        }

        $parts = preg_split('/^#{1,6}[ \t]/m', $body, 2);
        $description = trim(is_array($parts) ? $parts[0] : $body);

        return [
            'label' => $label !== null && $label !== '' ? $label : null,
            'description' => $description !== '' ? $description : null,
        ];
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

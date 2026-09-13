<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use Craft;
use craft\base\FieldInterface;
use craft\fields\Matrix;
use yii\base\Component;

/**
 * Wires the contract check to the project: finds each component's adapter in
 * the templates folder, reads the matched entry type's fields out of Craft, and
 * hands both to ContractChecker.
 *
 * Everything Craft-shaped lives here so the rules themselves stay testable on
 * plain arrays.
 */
class ContractInspector extends Component
{
    private const IGNORED_DIRS = ['node_modules', 'vendor', 'cache', '.git'];

    /** @var array<string, string[]>|null Entry-type handle => field handles. */
    private ?array $fieldCache = null;

    public function __construct(
        private readonly AdapterResolver $resolver,
        private readonly ContractChecker $checker,
        private readonly GalleryMatcher $matcher,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Contract errors per component id. Components with no matched entry type
     * are skipped: without a block behind them there is no contract.
     *
     * @param ComponentDefinition[] $components
     * @return array<string, ScanError[]>
     */
    public function inspect(array $components, string $templatesRoot): array
    {
        $bindings = $this->findAdapters($components, $templatesRoot);
        $errors = [];

        foreach ($components as $component) {
            $handle = $this->matcher->matchedEntryTypeHandle($component);
            if ($handle === null) {
                continue;
            }

            $found = $this->checker->check(
                $component,
                $bindings[$component->id] ?? null,
                $this->fieldHandles($handle),
                $this->nestedFieldHandles($handle),
            );

            if ($found !== []) {
                $errors[$component->id] = $found;
            }
        }

        return $errors;
    }

    /**
     * Story ids per component id that an editor can actually reproduce.
     *
     * The gallery previews these and nothing else: a card showing a state the
     * editor has no field to reach is the whole defect, and the card is where
     * they would meet it.
     *
     * @param ComponentDefinition[] $components
     * @return array<string, list<string>>
     */
    public function producibleStories(array $components, string $templatesRoot): array
    {
        $bindings = $this->findAdapters($components, $templatesRoot);
        $producible = [];

        foreach ($components as $component) {
            $handle = $this->matcher->matchedEntryTypeHandle($component);

            $producible[$component->id] = $this->checker->producibleStories(
                $component,
                $handle === null ? null : ($bindings[$component->id] ?? null),
                $handle === null ? [] : $this->fieldHandles($handle),
            );
        }

        return $producible;
    }

    /**
     * The adapter binding per component id, for callers that need the
     * argument-to-field mapping rather than a verdict about it.
     *
     * @param ComponentDefinition[] $components
     * @return array<string, AdapterBinding>
     */
    public function bindings(array $components, string $templatesRoot): array
    {
        return $this->findAdapters($components, $templatesRoot);
    }

    /**
     * One walk of the templates folder, matching every file against every
     * component. A component included from two places that both read a block is
     * ambiguous, and ambiguity is refused rather than guessed — the same rule
     * as for two templates that collide on a handle.
     *
     * @param ComponentDefinition[] $components
     * @return array<string, AdapterBinding>
     */
    private function findAdapters(array $components, string $templatesRoot): array
    {
        $candidates = [];

        foreach ($this->twigFiles($templatesRoot) as $absolute) {
            $source = @file_get_contents($absolute);
            if ($source === false || !str_contains($source, '{% include')) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen(rtrim($templatesRoot, '/')))), '/');

            foreach ($components as $component) {
                // A component that includes itself is not its own adapter.
                if ($this->samePath($relative, $component->templatePath)) {
                    continue;
                }

                $binding = $this->resolver->parse($source, $component->templatePath, $relative);

                // No block variable means the include passes literals or
                // another component's values — a composition, not an adapter.
                if ($binding !== null && $binding->blockRoot !== '') {
                    $candidates[$component->id][] = $binding;
                }
            }
        }

        $bindings = [];
        foreach ($candidates as $componentId => $found) {
            if (count($found) === 1) {
                $bindings[$componentId] = $found[0];
            }
        }

        return $bindings;
    }

    /**
     * @return list<string>
     */
    private function twigFiles(string $root): array
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $file): bool {
                    return !$file->isDir() || !in_array($file->getFilename(), self::IGNORED_DIRS, true);
                },
            ),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'twig') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function samePath(string $a, string $b): bool
    {
        $normalise = static function (string $path): string {
            $path = ltrim(str_replace('\\', '/', trim($path)), './');

            return str_ends_with($path, '.twig') ? substr($path, 0, -5) : $path;
        };

        return $normalise($a) === $normalise($b);
    }

    // --- Craft ---------------------------------------------------------------

    /**
     * Field handles on an entry type.
     *
     * @return string[]
     */
    private function fieldHandles(string $entryTypeHandle): array
    {
        if ($this->fieldCache === null) {
            $this->fieldCache = [];
        }

        if (!array_key_exists($entryTypeHandle, $this->fieldCache)) {
            $this->fieldCache[$entryTypeHandle] = array_map(
                static fn(FieldInterface $field): string => (string)$field->handle,
                $this->customFields($entryTypeHandle),
            );
        }

        return $this->fieldCache[$entryTypeHandle];
    }

    /**
     * For every Matrix field on the entry type, the field handles available on
     * the entry types inside it. A property read on a nested entry belongs to
     * these, never to the block.
     *
     * @return array<string, string[]>
     */
    private function nestedFieldHandles(string $entryTypeHandle): array
    {
        $nested = [];

        foreach ($this->customFields($entryTypeHandle) as $field) {
            if (!$field instanceof Matrix || !method_exists($field, 'getEntryTypes')) {
                continue;
            }

            $handles = [];
            foreach ($field->getEntryTypes() as $type) {
                foreach ($this->customFields((string)$type->handle) as $nestedField) {
                    $handles[(string)$nestedField->handle] = true;
                }
            }

            $nested[(string)$field->handle] = array_keys($handles);
        }

        return $nested;
    }

    /**
     * @return FieldInterface[]
     */
    private function customFields(string $entryTypeHandle): array
    {
        $service = Craft::$app->getEntries();
        if (!method_exists($service, 'getEntryTypeByHandle')) {
            return [];
        }

        $entryType = $service->getEntryTypeByHandle($entryTypeHandle);
        $layout = $entryType?->getFieldLayout();

        return $layout?->getCustomFields() ?? [];
    }
}

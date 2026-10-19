<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use Craft;
use craft\base\FieldInterface;
use craft\fields\Assets;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Checkboxes;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\MultiSelect;
use craft\fields\Number;
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

    /** @var array<string, array<string, AdapterBinding>> Adapter hunt results, per request. */
    private array $adapterCache = [];

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
        // `bindings()` and `producibleStories()` are both called while building
        // one picker map, and each used to walk the whole templates tree and
        // re-parse every file against every component. Memoising is not an
        // optimisation the answers can disagree about: same inputs, same
        // result — and that matters here, because the index and the gallery
        // must never answer differently about one component.
        $key = md5($templatesRoot . '|' . implode(',', array_map(
            static fn(ComponentDefinition $component): string => $component->id,
            $components,
        )));

        if (isset($this->adapterCache[$key])) {
            return $this->adapterCache[$key];
        }

        $candidates = [];

        foreach ($this->twigFiles($templatesRoot) as $absolute) {
            $source = @file_get_contents($absolute);
            // Cheap reject before the parser, but it has to allow whitespace
            // control (`{%- include`) and any spacing the author chose. The
            // literal '{% include' missed both, and a file rejected here is
            // never reported — it simply has no adapter, silently.
            if ($source === false || preg_match('/\{%-?\s*include\b/', $source) !== 1) {
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

        // No adapter — but the template may read the block itself, which is
        // how most projects are written. Two adapters (ambiguous) is not this
        // case: something does include the component, so it is not the block
        // template, and guessing between the two stays refused.
        foreach ($components as $component) {
            if (!isset($bindings[$component->id]) && !isset($candidates[$component->id])) {
                $self = $this->selfBinding($component);
                if ($self !== null) {
                    $bindings[$component->id] = $self;
                }
            }
        }

        return $this->adapterCache[$key] = $bindings;
    }

    /**
     * The binding of a component template that reads the block entry itself,
     * or null when it does not (or has no entry type to read it against).
     * See AdapterResolver::parseSelf().
     */
    public function selfBinding(ComponentDefinition $component): ?AdapterBinding
    {
        $handle = $this->matcher->matchedEntryTypeHandle($component);
        if ($handle === null) {
            return null;
        }

        $source = @file_get_contents($component->absoluteTemplatePath);
        if ($source === false) {
            return null;
        }

        $storyArgs = [];
        foreach ($component->stories as $story) {
            foreach (array_keys($story->args) as $arg) {
                $storyArgs[(string)$arg] = true;
            }
        }

        return $this->resolver->parseSelf(
            $source,
            $component->templatePath,
            array_keys($storyArgs),
            $this->fieldHandles($handle),
        );
    }

    /**
     * The matched entry type, described for StoryEntryBuilder: each field's
     * handle and the shape Craft gives its value. Matrix fields carry their
     * own entry types, one level down — deep enough for a block's cards, and a
     * bound on the walk.
     *
     * @return array{handle: string, name: string, fields: array<string, array<string, mixed>>}|null
     */
    public function entryTypeSchema(string $entryTypeHandle, int $depth = 0): ?array
    {
        $service = Craft::$app->getEntries();
        $entryType = $service->getEntryTypeByHandle($entryTypeHandle);
        if ($entryType === null) {
            return null;
        }

        $fields = [];
        foreach ($this->customFields($entryTypeHandle) as $field) {
            $spec = ['kind' => $this->fieldKind($field)];

            if ($field instanceof BaseOptionsField) {
                $labels = [];
                foreach ($field->options as $option) {
                    if (is_array($option) && isset($option['value']) && !isset($option['optgroup'])) {
                        $labels[(string)$option['value']] = (string)($option['label'] ?? $option['value']);
                    }
                }
                $spec['options'] = $labels;
            }

            if ($field instanceof Matrix && $depth < 1) {
                $types = [];
                foreach ($field->getEntryTypes() as $type) {
                    $nested = $this->entryTypeSchema((string)$type->handle, $depth + 1);
                    if ($nested !== null) {
                        $types[(string)$type->handle] = $nested;
                    }
                }
                $spec['types'] = $types;
            }

            $fields[(string)$field->handle] = $spec;
        }

        return [
            'handle' => (string)$entryType->handle,
            'name' => (string)$entryType->name,
            'fields' => $fields,
        ];
    }

    /**
     * The shape a field's value takes in a template. Anything not listed is a
     * plain value — text, email, URL, date — which a story string already is.
     */
    private function fieldKind(FieldInterface $field): string
    {
        $class = get_class($field);

        return match (true) {
            $field instanceof Matrix => 'matrix',
            $field instanceof Assets => 'assets',
            $field instanceof BaseRelationField => 'relation',
            $field instanceof Lightswitch => 'bool',
            $field instanceof Number => 'number',
            $field instanceof Checkboxes, $field instanceof MultiSelect => 'options',
            $field instanceof BaseOptionsField => 'option',
            class_exists('craft\\fields\\Link') && is_a($field, 'craft\\fields\\Link') => 'link',
            is_a($field, 'craft\\htmlfield\\HtmlField'),
            str_contains($class, '\\ckeditor\\'),
            str_contains($class, '\\redactor\\') => 'html',
            default => 'text',
        };
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
            // No method_exists() guard: `getEntryTypes()` has been on Matrix
            // since Craft 5.0, and composer.json requires ^5.0.0 — so the check
            // could never fail, and a guard that cannot fire only suggests a
            // doubt that does not exist.
            if (!$field instanceof Matrix) {
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

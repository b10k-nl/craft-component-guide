<?php

namespace b10k\componentguide\models;

use craft\base\Model;

/**
 * Component Guide settings.
 *
 * The scanner walks {@see $componentPath} recursively (relative to the site
 * templates directory) and shows every Twig template that has an adjacent story
 * file. Leave the path empty to scan the entire templates directory — the
 * "Storybook" model: any developer opts a component in simply by adding a
 * `*.stories.php` next to it.
 *
 * Absolute paths and directory traversal are rejected by validation and again at
 * scan time.
 */
class Settings extends Model
{
    /**
     * @var string Scan root, relative to the templates folder. Empty = the whole
     * templates directory. e.g. "_components" → "templates/_components".
     */
    public string $componentPath = '';

    /**
     * @var string Suffix that identifies a story definition file.
     */
    public string $storySuffix = '.stories.php';

    /**
     * @var bool Whether the control-panel section is enabled.
     */
    public bool $enableCpSection = true;

    /**
     * @var bool Whether previews render inside an isolated iframe.
     */
    public bool $enableIframePreview = true;

    /**
     * @var bool Whether scan results are cached persistently (Craft's cache
     * component). The cache key includes a filesystem fingerprint of every
     * story file, so it invalidates automatically when stories or templates
     * change — disabling this is only useful for debugging. Overridable via
     * config/component-guide.php.
     */
    public bool $enableScanCache = true;

    /**
     * @var string[] Front-end CSS URLs injected into the preview document.
     */
    public array $previewCss = [];

    /**
     * @var string[] Front-end JS URLs injected into the preview document.
     */
    public array $previewJs = [];

    /**
     * @var string Optional site template rendered into the preview <head>,
     * in SITE mode. Use it to emit whatever your frontend needs — e.g. Vite tags
     * ({{ craft.vite.script('src/js/app.js') }}) so HMR/Tailwind previews match
     * the real site. Leave empty to rely on previewCss / previewJs.
     */
    public string $previewTemplate = '';

    /**
     * The Twig story suffix that pairs with {@see $storySuffix}: the same
     * name with `.php` swapped for `.twig` (e.g. `.stories.twig`). Both
     * formats are discovered by the scanner; this is also the format the
     * story scaffolder writes by default.
     */
    public function twigStorySuffix(): string
    {
        return preg_replace('/\.php$/', '.twig', $this->storySuffix) ?? $this->storySuffix;
    }

    public function init(): void
    {
        parent::init();
        $this->normalizeAssetLists();
    }

    public function beforeValidate(): bool
    {
        // Settings saved from the CP form arrive AFTER init(), as raw strings
        // from textareas — normalize again so the rest of the request (and the
        // persisted value) always sees string arrays.
        $this->normalizeAssetLists();
        return parent::beforeValidate();
    }

    private function normalizeAssetLists(): void
    {
        // Allow single-string config/form values for the asset lists.
        $this->previewCss = $this->normalizeList($this->previewCss);
        $this->previewJs = $this->normalizeList($this->previewJs);
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            // Accept a textarea/config string: one URL per line (or comma).
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn($item) => is_string($item) ? trim($item) : '',
            $value
        ), static fn($item) => $item !== ''));
    }

    public function rules(): array
    {
        return [
            [['componentPath', 'storySuffix', 'previewTemplate'], 'trim'],
            [['storySuffix'], 'required'],
            [['enableCpSection', 'enableIframePreview', 'enableScanCache'], 'boolean'],
            [['previewCss', 'previewJs', 'previewTemplate'], 'safe'],
            ['storySuffix', 'match', 'pattern' => '/\.php$/', 'message' => 'The story suffix must end in “.php”.'],
            ['componentPath', 'validateComponentPath'],
        ];
    }

    /**
     * Rejects absolute paths and directory traversal in the scan root.
     */
    public function validateComponentPath(string $attribute): void
    {
        $value = (string)$this->$attribute;

        if ($value === '') {
            return;
        }

        $normalized = str_replace('\\', '/', $value);

        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized) === 1) {
            $this->addError($attribute, 'The scan root must be relative to the templates folder (no leading slash or drive letter).');
            return;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                $this->addError($attribute, 'The scan root must not contain “..” traversal segments.');
                return;
            }
        }
    }

    public function attributeLabels(): array
    {
        return [
            'componentPath' => 'Scan Root',
            'storySuffix' => 'Story File Suffix',
            'enableCpSection' => 'Enable Control-Panel Section',
            'enableIframePreview' => 'Isolated Iframe Preview',
            'enableScanCache' => 'Cache Scan Results',
            'previewCss' => 'Preview CSS',
            'previewJs' => 'Preview JavaScript',
            'previewTemplate' => 'Preview Template',
        ];
    }
}

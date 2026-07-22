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
     * @var string[] Front-end CSS URLs injected into the preview document.
     */
    public array $previewCss = [];

    /**
     * @var string[] Front-end JS URLs injected into the preview document.
     */
    public array $previewJs = [];

    public function init(): void
    {
        parent::init();

        // Allow single-string config values for the asset lists.
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
            [['componentPath', 'storySuffix'], 'trim'],
            [['storySuffix'], 'required'],
            [['enableCpSection', 'enableIframePreview'], 'boolean'],
            [['previewCss', 'previewJs'], 'safe'],
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
            'previewCss' => 'Preview CSS',
            'previewJs' => 'Preview JavaScript',
        ];
    }
}

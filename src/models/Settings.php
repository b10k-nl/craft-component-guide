<?php

namespace b10k\componentguide\models;

use craft\base\Model;
use craft\helpers\App;

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
     * @var bool Whether client review is on (Pro): approve a story or ask for
     * changes, on the component page. Off by default — an agency decides to
     * use it with its client, and the setting travels in project config, so
     * staging and production agree.
     */
    public bool|string $enableClientReview = false;

    /**
     * @var bool Whether admins can clear the review history from the control
     * panel. Off by default: the history is what an agency points to when a
     * client remembers it differently. Turn it on to clear trial runs before
     * the client starts — per environment with an env var in
     * config/component-guide.php, if production should never allow it. The
     * console command works either way.
     */
    public bool|string $enableReviewClearing = false;

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

    /**
     * The client review switches, which can also name an environment variable
     * (`$CG_…`), so staging and production can differ while project config is
     * the same — read them through isOn(), never directly. (Not `on()`: that
     * name is Yii's, for events, on every component.)
     */
    public const SWITCHES = [
        'enableClientReview' => false,
        'enableReviewClearing' => false,
    ];

    /**
     * Whether a switch is on: its value, or the environment variable it names
     * read as a boolean (`yes`/`no`/`true`/`false`/`on`/`off`/`1`/`0`). An
     * unset or unreadable variable gives the switch's default.
     */
    public function isOn(string $name): bool
    {
        if (!array_key_exists($name, self::SWITCHES)) {
            throw new \InvalidArgumentException("Unknown setting “{$name}”.");
        }

        return App::parseBooleanEnv($this->$name) ?? self::SWITCHES[$name];
    }

    /**
     * A switch as the settings form shows it: '1', '0', or the `$VARIABLE`.
     */
    public function formValue(string $name): string
    {
        $value = $this->$name;

        return is_string($value) && str_starts_with($value, '$') ? $value : ($value ? '1' : '0');
    }

    public function init(): void
    {
        parent::init();
        $this->normalizeAssetLists();
        $this->normalizeSwitches();
    }

    public function beforeValidate(): bool
    {
        // Settings saved from the CP form arrive AFTER init(), as raw strings
        // from textareas — normalize again so the rest of the request (and the
        // persisted value) always sees string arrays.
        $this->normalizeAssetLists();
        $this->normalizeSwitches();
        return parent::beforeValidate();
    }

    /**
     * '1' / '0' / '' from the form, or true/false from config, to a bool; a
     * `$VARIABLE` stays as it is, to be read when it is asked for.
     */
    private function normalizeSwitches(): void
    {
        foreach (array_keys(self::SWITCHES) as $name) {
            $value = $this->$name;
            if (is_string($value) && str_starts_with(trim($value), '$')) {
                $this->$name = trim($value);
            } elseif (is_string($value)) {
                $this->$name = in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
            } else {
                $this->$name = (bool)$value;
            }
        }
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
            [array_keys(self::SWITCHES), 'validateSwitch'],
            [['previewCss', 'previewJs', 'previewTemplate'], 'safe'],
            ['storySuffix', 'match', 'pattern' => '/\.php$/', 'message' => 'The story suffix must end in “.php”.'],
            ['componentPath', 'validateComponentPath'],
        ];
    }

    /**
     * A switch is on, off, or names an environment variable.
     */
    public function validateSwitch(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_bool($value) && !(is_string($value) && preg_match('/^\$\w+$/', $value) === 1)) {
            $this->addError($attribute, 'Choose Yes, No, or an environment variable.');
        }
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

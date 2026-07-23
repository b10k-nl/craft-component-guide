<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ScanError;
use b10k\componentguide\models\StoryCollector;
use Craft;
use craft\web\View;
use yii\base\Component;

/**
 * Loads a Twig story template (`*.stories.twig`) into StoryDefinitions.
 *
 * A Twig story file is pure data — two top-level `{% set %}`s and no calls:
 *
 *     {% set meta = { title: 'Button', group: 'UI' } %}
 *     {% set stories = {
 *         'Primary': { args: { label: 'Save' } },
 *     } %}
 *
 * The loader appends a hidden `{% do %}` to the template source that hands
 * those variables to a collector, then renders the combined source in SITE
 * mode (output discarded). The collected data is normalized by the same
 * StoryParser pipeline as the PHP format, so both formats behave identically
 * downstream.
 */
class TwigStoryLoader extends Component
{
    /** Context variable name reserved for the internal collector. */
    private const COLLECTOR_VAR = '__componentGuideCollector';

    public function __construct(private StoryParser $storyParser, array $config = [])
    {
        parent::__construct($config);
    }

    /**
     * @param string $absolutePath Absolute path to the story template.
     * @param string $relativeFile Story file path relative to the templates root.
     * @return array{meta: array<string, string>, stories: \b10k\componentguide\models\StoryDefinition[], errors: ScanError[]}
     */
    public function load(string $absolutePath, string $relativeFile): array
    {
        $source = @file_get_contents($absolutePath);
        if ($source === false) {
            return ['meta' => [], 'stories' => [], 'errors' => [
                new ScanError(
                    ScanError::STORY_FILE_LOAD_ERROR,
                    'Story file is missing or unreadable.',
                    $relativeFile,
                ),
            ]];
        }

        $collector = new StoryCollector();

        // Top-level {% set %} variables stay in scope for source appended to the
        // same template body — this is what lets story files be pure data.
        $source .= "\n{% do " . self::COLLECTOR_VAR . ".collect(meta ?? null, stories ?? null) %}";

        $view = Craft::$app->getView();

        try {
            // Rendered for side effects only — the collector gathers the stories.
            $view->renderString($source, [self::COLLECTOR_VAR => $collector], View::TEMPLATE_MODE_SITE);
        } catch (\Throwable $e) {
            return ['meta' => [], 'stories' => [], 'errors' => [
                new ScanError(
                    ScanError::STORY_FILE_LOAD_ERROR,
                    'Story template threw while rendering.',
                    $relativeFile,
                    details: $e->getMessage(),
                ),
            ]];
        }

        if (!$collector->hasStories()) {
            return ['meta' => [], 'stories' => [], 'errors' => [
                new ScanError(
                    ScanError::INVALID_STORY_FORMAT,
                    'Story template must set a `stories` variable: {% set stories = { \'Name\': { args: { … } } } %}.',
                    $relativeFile,
                ),
            ]];
        }

        return $this->storyParser->parseData($collector->getData(), $relativeFile);
    }
}

<?php

namespace b10k\componentguide\console\controllers;

use b10k\componentguide\Plugin;
use craft\helpers\Console;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * CLI diagnostics for Component Guide.
 *
 * `php craft component-guide/components/scan`
 *
 * Runs the same discovery the CP uses, so you can verify configuration and see
 * discovered components (and any errors) without opening the control panel.
 */
class ComponentsController extends Controller
{
    /**
     * @var string Story format written by `make`: "twig" (default) or "php".
     */
    public string $format = 'twig';

    /**
     * @var bool Scaffold one story per state the template switches on
     * (`theme == 'dark'`, `mediaPosition == 'right'`, …) instead of a single
     * "Default".
     */
    public bool $states = false;

    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            $actionID === 'make' ? ['format', 'states'] : [],
        );
    }

    /**
     * Renders one story's full preview document to stdout — useful for
     * verifying preview config (previewCss/previewTemplate) without a browser.
     *
     * `php craft component-guide/components/render <componentId> <storyId>`
     */
    public function actionRender(string $componentId, string $storyId): int
    {
        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($componentId);
        if ($component === null) {
            $this->stderr("Component not found: {$componentId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $story = $component->getStory($storyId);
        if ($story === null) {
            $this->stderr("Story not found: {$storyId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $renderer = $plugin->getPreviewRenderer();
        $result = $renderer->render($component, $story);
        $doc = $renderer->renderDocument($component, $story, $result);

        if (!$result->success) {
            $this->stderr("Render failed: {$result->error}\n", Console::FG_RED);
            if ($result->details !== null) {
                $this->stderr($result->details . "\n", Console::FG_YELLOW);
            }
        }

        $this->stdout($doc . "\n");
        return $result->success ? ExitCode::OK : ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Generates a skeleton story file for an undocumented component from its
     * template's variables (marked `status: draft`; never overwrites).
     *
     * `php craft component-guide/components/make <componentId> [--format=php] [--states]`
     */
    public function actionMake(string $componentId): int
    {
        $plugin = Plugin::getInstance();

        if (!in_array($this->format, ['twig', 'php'], true)) {
            $this->stderr("Unknown --format “{$this->format}”; use “twig” or “php”.\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $component = $plugin->getRepository()->getById($componentId);
        if ($component === null) {
            $this->stderr("Component not found: {$componentId}\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $settings = $plugin->getSettings();
        $suffix = $this->format === 'php' ? $settings->storySuffix : $settings->twigStorySuffix();

        try {
            $path = $plugin->getStoryScaffolder()->scaffold($component, $suffix, $this->states);
        } catch (\RuntimeException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Story scaffold written: {$path}\n", Console::FG_GREEN);
        $this->stdout("The args are guesses — review them until the preview looks right.\n");
        return ExitCode::OK;
    }

    public function actionScan(): int
    {
        $repository = Plugin::getInstance()->getRepository();
        $components = $repository->getAll();

        $undocumented = $repository->undocumentedCount();
        $this->stdout(sprintf(
            "Found %d component(s), %d story(ies)%s.\n\n",
            $repository->componentCount(),
            $repository->storyCount(),
            $undocumented > 0 ? sprintf(', %d without stories', $undocumented) : '',
        ), Console::FG_GREEN);

        foreach ($components as $component) {
            $flag = ($component->isDocumented ? '' : ' [no story]')
                . ($component->hasErrors() ? ' [errors]' : '');
            $this->stdout(sprintf(
                "  %-28s %s (%d)%s\n",
                $component->id,
                $component->effectiveGroup(),
                $component->storyCount(),
                $flag,
            ));
        }

        $globalErrors = $repository->getErrors();
        $componentErrors = [];
        foreach ($components as $component) {
            foreach ($component->errors as $error) {
                $componentErrors[] = $error;
            }
        }
        $allErrors = array_merge($globalErrors, $componentErrors);

        if ($allErrors !== []) {
            $this->stdout("\nErrors:\n", Console::FG_YELLOW);
            foreach ($allErrors as $error) {
                $this->stdout(sprintf(
                    "  [%s] %s%s\n",
                    $error->type,
                    $error->message,
                    $error->file ? " ({$error->file})" : '',
                ), Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }
}

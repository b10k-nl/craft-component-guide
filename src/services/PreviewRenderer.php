<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\RenderResult;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;
use Craft;
use craft\web\View;
use yii\base\Component;

/**
 * Renders a component template with a story's args, isolated from CP context.
 *
 * Rendering happens in SITE template mode with only the story args in scope —
 * the equivalent of `{% include template with args only %}`. Errors are caught
 * and returned structurally; stack traces are exposed only in dev mode.
 */
class PreviewRenderer extends Component
{
    public function render(ComponentDefinition $component, StoryDefinition $story): RenderResult
    {
        if (!is_file($component->absoluteTemplatePath)) {
            return RenderResult::failure(
                sprintf('Template “%s” was not found.', $component->templatePath),
            );
        }

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            // Placeholder tokens (@lorem…, @image…, @icon…) are expanded here
            // rather than in the parser, so story files stay short and the
            // resolved content is identical on every render (the seed is the
            // component + story + argument path).
            $args = Plugin::getInstance()->getPlaceholderResolver()
                ->resolveArgs($story->args, $component->id . '/' . $story->id);

            // renderTemplate scopes the template to exactly these variables
            // (plus globals) — story args never inherit CP template state.
            $html = $view->renderTemplate($component->templatePath, $args, View::TEMPLATE_MODE_SITE);
            return RenderResult::success($html);
        } catch (\Throwable $e) {
            Craft::error(
                sprintf('Failed to render component “%s” story “%s”: %s', $component->id, $story->id, $e->getMessage()),
                'component-guide',
            );

            $general = Craft::$app->getConfig()->getGeneral();
            // The message alone is what a developer needs 99% of the time and
            // is safe wherever project files may be edited; the stack trace
            // stays behind devMode. Showing nothing at all just turns a typo
            // into a mystery.
            $details = null;
            if ($general->devMode) {
                $details = $e->getMessage() . "\n\n" . $e->getTraceAsString();
            } elseif ($general->allowAdminChanges) {
                $details = $e->getMessage();
            }

            return RenderResult::failure('The component template threw while rendering.', $details);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }

    /**
     * Renders the full iframe preview document for a story.
     *
     * The wrapper document is rendered in CP mode (that's where the plugin's
     * own templates live); the component HTML and the preview-head markup were
     * both rendered in SITE mode and are injected as trusted strings. Shared
     * by the web preview controller and the CLI diagnostics.
     */
    public function renderDocument(ComponentDefinition $component, StoryDefinition $story, RenderResult $result): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return Craft::$app->getView()->renderTemplate('component-guide/preview/document', [
            'result' => $result,
            'story' => $story,
            'component' => $component,
            'previewCss' => $settings->previewCss,
            'previewJs' => $settings->previewJs,
            'previewHead' => $this->renderPreviewHead($settings->previewTemplate),
            'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Renders the optional project-supplied preview-head template (e.g. Vite
     * asset tags) in SITE mode, returning its markup — or '' if unset,
     * missing or failed. Errors are logged, never fatal.
     */
    private function renderPreviewHead(string $template): string
    {
        if ($template === '') {
            return '';
        }

        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            if (!$view->doesTemplateExist($template)) {
                Craft::warning("Component Guide preview template “{$template}” not found.", 'component-guide');
                return '';
            }
            return $view->renderTemplate($template, [], View::TEMPLATE_MODE_SITE);
        } catch (\Throwable $e) {
            Craft::error('Component Guide preview template failed: ' . $e->getMessage(), 'component-guide');
            return '';
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }
}

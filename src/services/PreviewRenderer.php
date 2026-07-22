<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\RenderResult;
use b10k\componentguide\models\StoryDefinition;
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
            // renderTemplate scopes the template to exactly these variables
            // (plus globals) — story args never inherit CP template state.
            $html = $view->renderTemplate($component->templatePath, $story->args, View::TEMPLATE_MODE_SITE);
            return RenderResult::success($html);
        } catch (\Throwable $e) {
            Craft::error(
                sprintf('Failed to render component “%s” story “%s”: %s', $component->id, $story->id, $e->getMessage()),
                'component-guide',
            );

            $details = Craft::$app->getConfig()->getGeneral()->devMode
                ? $e->getMessage() . "\n\n" . $e->getTraceAsString()
                : null;

            return RenderResult::failure('The component template threw while rendering.', $details);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }
}

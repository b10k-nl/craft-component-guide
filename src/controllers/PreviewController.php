<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\Plugin;
use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves the isolated iframe preview document for a single component story.
 *
 * Components and stories are resolved only through the repository by ID — a
 * request can never supply a raw template path to render.
 */
class PreviewController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_ACCESS);
        return true;
    }

    public function actionRender(string $componentId, string $storyId): Response
    {
        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($componentId);
        if ($component === null) {
            throw new NotFoundHttpException('Component not found.');
        }

        $story = $component->getStory($storyId);
        if ($story === null) {
            throw new NotFoundHttpException('Story not found.');
        }

        $result = $plugin->getPreviewRenderer()->render($component, $story);
        $settings = $plugin->getSettings();

        // Optional project-supplied head markup (e.g. Vite/asset tags), rendered
        // in SITE mode so craft.vite.* and site aliases resolve like the frontend.
        $previewHead = $this->renderPreviewHead($settings->previewTemplate);

        // The wrapper document is rendered in CP mode (that's where the plugin's
        // own templates live); the component HTML and the preview-head markup were
        // both rendered in SITE mode and are injected as trusted strings.
        $html = Craft::$app->getView()->renderTemplate('component-guide/preview/document', [
            'result' => $result,
            'story' => $story,
            'component' => $component,
            'previewCss' => $settings->previewCss,
            'previewJs' => $settings->previewJs,
            'previewHead' => $previewHead,
            'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
        ], View::TEMPLATE_MODE_CP);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->data = $html;
        $response->getHeaders()
            ->set('Content-Type', 'text/html; charset=UTF-8')
            ->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Renders the optional preview-head template in SITE mode, returning its
     * markup (or '' if unset/missing/failed). Errors are logged, never fatal.
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

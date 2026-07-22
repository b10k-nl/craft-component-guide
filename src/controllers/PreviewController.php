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

        // The wrapper document is rendered in CP mode; the component HTML inside
        // it was already rendered (and escaped-or-trusted) by PreviewRenderer.
        $html = Craft::$app->getView()->renderTemplate('component-guide/preview/document', [
            'result' => $result,
            'story' => $story,
            'component' => $component,
            'previewCss' => $settings->previewCss,
            'previewJs' => $settings->previewJs,
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
}

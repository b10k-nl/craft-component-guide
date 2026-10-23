<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\helpers\StoryControls;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;
use Craft;
use craft\web\Controller;
use InvalidArgumentException;
use yii\web\BadRequestHttpException;
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
        // Deliberately no requireCpRequest(): the preview is served from a
        // site route so that Twig extensions other plugins register for site
        // requests are present when the component template compiles. Access is
        // still gated — a session and the plugin's own permission.
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

        // The controls on the component page post the values someone changed.
        // Only ever in a POST body, so they arrive with the CSRF token of the
        // person who typed them: a link can open a story, but it cannot make
        // someone else's preview render values of its choosing. They are data
        // for the same template, nothing more — no path, no other component.
        $request = Craft::$app->getRequest();
        if ($request->getIsPost()) {
            try {
                $overrides = StoryControls::decodeOverrides((string)$request->getBodyParam('args', ''));
            } catch (InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage(), 0, $e);
            }
            $story = StoryControls::withOverrides($story, $overrides);
        }

        return $this->document($component, $story);
    }

    /**
     * An editor's own variant (see UserStoriesController). Looked up in the
     * signed-in user's preferences only, so the URL can never reach anyone
     * else's — and never carries args of its own.
     */
    public function actionRenderMine(string $componentId, string $userStoryId): Response
    {
        $plugin = Plugin::getInstance();
        $record = $plugin->getUserStories()->find($userStoryId);
        if ($record === null || $record['componentId'] !== $componentId) {
            throw new NotFoundHttpException('Variant not found.');
        }

        $component = $plugin->getRepository()->getById($componentId);
        if ($component === null) {
            throw new NotFoundHttpException('Component not found.');
        }

        $bindings = $plugin->getContractInspector()->bindings(
            [$component],
            Craft::$app->getPath()->getSiteTemplatesPath(),
        );
        $story = $plugin->getUserStories()->story($component, $record, $bindings[$component->id] ?? null);
        if ($story === null) {
            throw new NotFoundHttpException('Story not found.');
        }

        return $this->document($component, $story);
    }

    private function document(ComponentDefinition $component, StoryDefinition $story): Response
    {
        $renderer = Plugin::getInstance()->getPreviewRenderer();
        $result = $renderer->render($component, $story);
        $html = $renderer->renderDocument($component, $story, $result);

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->data = $html;
        $response->getHeaders()
            ->set('Content-Type', 'text/html; charset=UTF-8')
            ->set('X-Robots-Tag', 'noindex, nofollow')
            ->set('Cache-Control', 'no-store');

        return $response;
    }
}

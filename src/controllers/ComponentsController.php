<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\Plugin;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders the component index and detail pages in the control panel.
 *
 * Thin: it validates the request, reads normalized data from the repository, and
 * renders CP templates. All discovery/rendering logic lives in services.
 */
class ComponentsController extends Controller
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

    public function actionIndex(): Response
    {
        $repository = Plugin::getInstance()->getRepository();

        return $this->renderTemplate('component-guide/components/index', [
            'title' => \Craft::t('component-guide', 'Component Guide'),
            'grouped' => $repository->getGrouped(),
            'componentCount' => $repository->componentCount(),
            'storyCount' => $repository->storyCount(),
            'scanErrors' => $repository->getErrors(),
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionView(string $componentId, ?string $storyId = null): Response
    {
        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($componentId);

        if ($component === null) {
            throw new NotFoundHttpException('Component not found.');
        }

        $story = $storyId !== null
            ? $component->getStory($storyId)
            : ($component->stories[0] ?? null);

        if ($storyId !== null && $story === null) {
            throw new NotFoundHttpException('Story not found.');
        }

        $snippet = $story !== null
            ? $plugin->getSnippetGenerator()->generate($component->templatePath, $story->args)
            : null;

        return $this->renderTemplate('component-guide/components/view', [
            'title' => $component->title,
            'component' => $component,
            'story' => $story,
            'snippet' => $snippet,
            'enableIframePreview' => $plugin->getSettings()->enableIframePreview,
        ]);
    }
}

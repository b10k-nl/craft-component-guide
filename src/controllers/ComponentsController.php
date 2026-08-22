<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\Plugin;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
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
        $matcher = Plugin::getInstance()->getGalleryMatcher();
        $components = $repository->getAll();

        return $this->renderTemplate('component-guide/components/index', [
            'title' => \Craft::t('component-guide', 'Component Guide'),
            'grouped' => $repository->getGrouped(),
            'componentCount' => $repository->componentCount(),
            'storyCount' => $repository->storyCount(),
            'scanErrors' => $repository->getErrors(),
            'groupMeta' => $repository->getGroupMeta(),
            'undocumentedCount' => $repository->undocumentedCount(),
            // Kept in sync with the scanner so onboarding copy can't drift.
            'markerFiles' => \b10k\componentguide\services\ComponentScanner::MARKER_FILES,
            'canScaffold' => $this->canScaffold(),
            'statesByComponent' => $this->detectStates($components),
            // What the editor half of the plugin actually amounts to on THIS
            // project, as a number rather than a claim. `entryTypeNames` is
            // every component the gallery knows about (disabled and story-less
            // included); `galleryReadyCount` is the subset an editor can add
            // and see — the completed handoffs.
            'entryTypeNames' => $matcher->entryTypeNames($components),
            'galleryReadyCount' => $matcher->countReadyForEditors($components),
            'galleryMatchedCount' => $matcher->countMatched($components),
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

        $matcher = $plugin->getGalleryMatcher();

        return $this->renderTemplate('component-guide/components/view', [
            'title' => $component->title,
            'component' => $component,
            'entryTypeName' => $matcher->matchedEntryType($component),
            'readyForEditors' => $matcher->isReadyForEditors($component),
            'story' => $story,
            'snippet' => $snippet,
            'enableIframePreview' => $plugin->getSettings()->enableIframePreview,
        ]);
    }

    /**
     * How many built-in states the scaffolder can find per undocumented
     * component, so the index can offer “one story per state” only where that
     * would actually produce more than one story.
     *
     * Costs one small file read per undocumented component, and only where
     * scaffolding is available at all.
     *
     * @param \b10k\componentguide\models\ComponentDefinition[] $components
     * @return array<string, array{var: string, values: string[]}>
     */
    private function detectStates(array $components): array
    {
        if (!$this->canScaffold()) {
            return [];
        }

        $scaffolder = Plugin::getInstance()->getStoryScaffolder();
        $states = [];

        foreach ($components as $component) {
            if ($component->isDocumented || !is_readable($component->absoluteTemplatePath)) {
                continue;
            }
            $source = @file_get_contents($component->absoluteTemplatePath);
            if ($source === false) {
                continue;
            }
            $detected = $scaffolder->detectStates($source);
            if ($detected !== null) {
                $states[$component->id] = $detected;
            }
        }

        return $states;
    }

    /**
     * Whether story files may be written from the control panel.
     *
     * Gated on `allowAdminChanges` rather than `devMode`: it is Craft's own
     * signal for “this environment may change project files” (true on local
     * and staging by convention, false on production), so the button appears
     * exactly where a developer expects it to.
     */
    private function canScaffold(): bool
    {
        return \Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    /**
     * Generates a story scaffold for an undocumented component and redirects
     * to its (now documented) detail page.
     *
     * Writes into the project's templates directory, so it follows Craft's
     * `allowAdminChanges` — see {@see canScaffold()}. The index renders an
     * explanatory notice instead of the button where that is off.
     */
    public function actionScaffold(): Response
    {
        $this->requirePostRequest();

        if (!$this->canScaffold()) {
            throw new ForbiddenHttpException('Story scaffolding is disabled in this environment (allowAdminChanges).');
        }

        $componentId = (string)$this->request->getRequiredBodyParam('componentId');
        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($componentId);

        if ($component === null) {
            throw new NotFoundHttpException('Component not found.');
        }

        try {
            // Twig is the scaffold default: same language as the component.
            $plugin->getStoryScaffolder()->scaffold(
                $component,
                $plugin->getSettings()->twigStorySuffix(),
                (bool)$this->request->getBodyParam('states'),
            );
        } catch (\RuntimeException $e) {
            $this->setFailFlash($e->getMessage());
            return $this->redirect('component-guide');
        }

        $this->setSuccessFlash(\Craft::t(
            'component-guide',
            'Story scaffold created — the args are guesses, review them until the preview looks right.',
        ));

        // The new story file changes the scan fingerprint, so the fresh scan
        // already sees this component as documented.
        return $this->redirect('component-guide/components/' . $component->id);
    }
}

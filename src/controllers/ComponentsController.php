<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\Plugin;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
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

        // One preview URL per documented component, built in the one place that
        // decides site-vs-CP, so the cards and the picker cannot drift apart.
        $previewUrls = [];
        foreach ($components as $component) {
            $firstStory = $component->stories[0] ?? null;
            if ($firstStory !== null) {
                $previewUrls[$component->id] = Plugin::previewUrl($component->id, $firstStory->id);
            }
        }

        // Three files can each be correct on their own and still combine into
        // a card no editor can reproduce — the story shows it, the adapter
        // passes it through, the entry type has no field for it. Computed here
        // rather than during the scan because it depends on entry types, which
        // change without any template changing.
        $contractErrors = Plugin::getInstance()->getContractInspector()->inspect(
            $components,
            \Craft::$app->getPath()->getSiteTemplatesPath(),
        );

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
            'scaffoldBlockedReason' => $this->scaffoldBlockedReason(),
            'statesByComponent' => $this->detectStates($components),
            // What the editor half of the plugin actually amounts to on THIS
            // project, as a number rather than a claim. `entryTypeNames` is
            // every component the gallery knows about (disabled and story-less
            // included); `galleryReadyCount` is the subset an editor can add
            // and see — the completed handoffs.
            'entryTypeNames' => $matcher->entryTypeNames($components),
            'galleryReadyCount' => $matcher->countReadyForEditors($components),
            'galleryMatchedCount' => $matcher->countMatched($components),
            // Files the CP itself changed and that are still, verifiably, in
            // that changed state — see WriteJournal. Relative paths only: the
            // CP never echoes absolute paths.
            'previewUrls' => $previewUrls,
            'contractErrors' => $contractErrors,
            'cpWrites' => $this->cpWrites(),
            // The status toggle writes into templates/ exactly like the
            // scaffolder, so it opens and closes with the same two gates.
            'canToggleStatus' => $this->canScaffold(),
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
            'previewUrl' => $story !== null
                ? Plugin::previewUrl($component->id, $story->id)
                : null,
            'snippet' => $snippet,
            // Same gate as the index toggle; the detail page is where a
            // reviewer actually looks at the preview before promoting.
            'canToggleStatus' => $this->canScaffold(),
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
     * Two independent gates, and the index needs to know which one closed —
     * an absent button with no reason is a mystery, and the two reasons call
     * for different actions.
     *
     * @return null|'admin-changes'|'read-only' Null when scaffolding is available.
     */
    private function scaffoldBlockedReason(): ?string
    {
        // Craft's own signal for “this environment may change project files”:
        // true on local and staging by convention, false on production.
        if (!\Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return 'admin-changes';
        }

        // Verified on Craft Cloud, 24.08.2026: with allowAdminChanges on, the
        // button appeared and the write failed, because the deployed
        // filesystem is read-only. Offering an action the environment cannot
        // perform is worse than not offering it.
        if (!is_writable(\Craft::$app->getPath()->getSiteTemplatesPath())) {
            return 'read-only';
        }

        return null;
    }

    private function canScaffold(): bool
    {
        return $this->scaffoldBlockedReason() === null;
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
            throw new ForbiddenHttpException($this->scaffoldBlockedReason() === 'read-only'
                ? 'The templates directory is read-only on this environment, so story files cannot be created here.'
                : 'Story scaffolding is disabled in this environment (allowAdminChanges).');
        }

        $componentId = (string)$this->request->getRequiredBodyParam('componentId');
        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($componentId);

        if ($component === null) {
            throw new NotFoundHttpException('Component not found.');
        }

        try {
            // Twig is the scaffold default: same language as the component.
            $storyPath = $plugin->getStoryScaffolder()->scaffold(
                $component,
                $plugin->getSettings()->twigStorySuffix(),
                (bool)$this->request->getBodyParam('states'),
            );
            $hash = @sha1_file($storyPath);
            if ($hash !== false) {
                $plugin->getWriteJournal()->record($storyPath, $hash, 'scaffold');
            }
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

    /**
     * Flips a documented component between `draft` and `stable` — the one
     * status change that is a review decision rather than a design one.
     *
     * `beta` and `deprecated` are left to the IDE on purpose: both express
     * developer intent about a component's lifecycle, not a verdict on a
     * generated story. The toggle exists because an agent or the scaffolder
     * can leave forty drafts behind, and promoting each one by opening a file
     * is the kind of chore that makes “help” feel like more work.
     *
     * Writes into templates/, so it shares the scaffolder's gates, and every
     * write is journalled so the index can report it — the CP has no
     * `git status`, so it has to say what it did itself.
     */
    public function actionSetStatus(): Response
    {
        $this->requirePostRequest();

        if (!$this->canScaffold()) {
            throw new ForbiddenHttpException($this->scaffoldBlockedReason() === 'read-only'
                ? 'The templates directory is read-only on this environment, so story files cannot be changed here.'
                : 'Changing story files is disabled in this environment (allowAdminChanges).');
        }

        $componentId = (string)$this->request->getRequiredBodyParam('componentId');
        $status = (string)$this->request->getRequiredBodyParam('status');

        if (!in_array($status, ['draft', 'stable'], true)) {
            throw new BadRequestHttpException('Only draft and stable can be set from the control panel.');
        }

        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($componentId);

        if ($component === null || !$component->isDocumented) {
            throw new NotFoundHttpException('Component not found.');
        }

        // A story without a status, or with beta/deprecated, was written that
        // way by hand. The toggle is for reviewing drafts, not for overriding
        // decisions.
        if (!in_array($component->status, ['draft', 'stable'], true)) {
            throw new BadRequestHttpException('This component’s status is set in its story file and is not a draft/stable toggle.');
        }

        $storyPath = $this->storyAbsolutePath($component->storyFilePath);

        try {
            $hash = $plugin->getStoryStatusWriter()->setStatus($storyPath, $status);
            $plugin->getWriteJournal()->record($storyPath, $hash, 'status');
        } catch (\RuntimeException $e) {
            return $this->asFailure($e->getMessage());
        }

        $message = $status === 'stable'
            ? \Craft::t('component-guide', '“{title}” is stable — editors can now add it from the blocks gallery.', ['title' => $component->title])
            : \Craft::t('component-guide', '“{title}” is back to draft.', ['title' => $component->title]);

        // The file's mtime changed, so the scan fingerprint changed and the
        // next read is a fresh scan. The reviewer is halfway down a list of
        // forty cards, though, so nothing reloads: the response carries every
        // piece of state the page shows about this component, and the JS
        // repaints it in place.
        $matcher = $plugin->getGalleryMatcher();
        $repository = $plugin->getRepository();
        // Drops the in-request memoization; the file's new mtime changes the
        // scan fingerprint, so this reads fresh rather than from cache.
        $repository->flush();
        $updated = $repository->getById($componentId) ?? $component;
        $components = $repository->getAll();

        return $this->asSuccess($message, [
            'componentId' => $component->id,
            'status' => $status,
            'entryTypeName' => $matcher->matchedEntryType($updated),
            'inGallery' => $matcher->isReadyForEditors($updated),
            'galleryReadyCount' => $matcher->countReadyForEditors($components),
            'writes' => $this->cpWrites(),
        ]);
    }

    /**
     * Clears the “changed from the control panel” banner, for one file or all.
     * The files themselves are untouched — this only says “I have seen it”.
     */
    public function actionMarkReviewed(): Response
    {
        $this->requirePostRequest();

        $relative = $this->request->getBodyParam('file');
        $journal = Plugin::getInstance()->getWriteJournal();

        if (is_string($relative) && $relative !== '') {
            $journal->markReviewed($this->storyAbsolutePath($relative));
        } else {
            $journal->markReviewed();
        }

        return $this->asSuccess(data: ['writes' => $this->cpWrites()]);
    }

    /**
     * Journal entries as the template may show them: path relative to the
     * templates root, the kind of write, and when.
     *
     * @return array<int, array{file: string, action: string, time: int}>
     */
    private function cpWrites(): array
    {
        $root = rtrim(str_replace('\\', '/', \Craft::$app->getPath()->getSiteTemplatesPath()), '/') . '/';
        $rows = [];

        foreach (Plugin::getInstance()->getWriteJournal()->entries() as $absolute => $entry) {
            $normalized = str_replace('\\', '/', $absolute);
            $rows[] = [
                'file' => str_starts_with($normalized, $root) ? substr($normalized, strlen($root)) : basename($normalized),
                'action' => (string)($entry['action'] ?? ''),
                'time' => (int)($entry['time'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Story paths are stored relative to the templates root and must stay that
     * way in the CP; this is the one place they become absolute again.
     */
    private function storyAbsolutePath(string $relative): string
    {
        return rtrim(\Craft::$app->getPath()->getSiteTemplatesPath(), '/\\')
            . DIRECTORY_SEPARATOR
            . ltrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
    }
}

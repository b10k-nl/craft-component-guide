<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\helpers\UserStoryBuilder;
use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\Plugin;
use Craft;
use craft\web\Controller;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * An editor's own variants of a block: save one from a block on the page,
 * delete it, or hand it to a developer as a story.
 *
 * Every action works on the signed-in user's variants only — there is no id
 * that reaches anyone else's, because they are never looked up anywhere but in
 * the current user's own preferences.
 */
class UserStoriesController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        $this->requirePermission(Plugin::PERMISSION_ACCESS);
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        return true;
    }

    /**
     * Saving writes on the user's behalf, so it is the paid half — the same
     * line the gallery draws at inserting a block. Checked here, on the server;
     * the menu item only says so.
     */
    public function actionSave(): Response
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->isPro()) {
            throw new ForbiddenHttpException(
                Craft::t('component-guide', 'Saving your own variants is a Pro feature.'),
            );
        }

        $request = Craft::$app->getRequest();
        $component = $this->component((string)$request->getRequiredBodyParam('componentId'));

        $title = UserStoryBuilder::title((string)$request->getBodyParam('title', ''));
        if ($title === null) {
            return $this->asFailure(Craft::t('component-guide', 'Give the variant a name.'));
        }

        $binding = $this->binding($component);
        if ($binding === null) {
            return $this->asFailure(Craft::t(
                'component-guide',
                'The guide cannot read how this block’s fields reach its template, so it cannot save a variant of it.',
            ));
        }

        $editable = UserStoryBuilder::editableFields($binding, $component->stories);
        $values = $request->getBodyParam('values', []);
        $values = UserStoryBuilder::cleanValues(is_array($values) ? $values : [], $editable);
        if ($values === []) {
            return $this->asFailure(Craft::t(
                'component-guide',
                'None of this block’s fields can be saved in a variant yet.',
            ));
        }

        $baseStoryId = (string)$request->getBodyParam('baseStoryId', '');
        if ($component->getStory($baseStoryId) === null) {
            $baseStoryId = $component->stories[0]->id ?? '';
        }

        try {
            $record = $plugin->getUserStories()->add($component->id, $baseStoryId, $title, $values);
        } catch (InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asJson([
            'id' => $record['id'],
            'title' => $record['title'],
            'saved' => array_keys($values),
        ]);
    }

    public function actionDelete(): Response
    {
        $id = (string)Craft::$app->getRequest()->getRequiredBodyParam('id');
        if (!Plugin::getInstance()->getUserStories()->delete($id)) {
            throw new NotFoundHttpException('Variant not found.');
        }

        return $this->asJson(['deleted' => $id]);
    }

    /**
     * The variant as Twig, for a developer to paste into the story file —
     * the explicit hand-over chosen over letting developers read editors'
     * preferences, which are personal and cannot be queried efficiently.
     */
    public function actionShare(): Response
    {
        $plugin = Plugin::getInstance();
        $record = $plugin->getUserStories()->find((string)Craft::$app->getRequest()->getRequiredBodyParam('id'));
        if ($record === null) {
            throw new NotFoundHttpException('Variant not found.');
        }

        $component = $this->component($record['componentId']);
        $story = $plugin->getUserStories()->story($component, $record, $this->binding($component));
        if ($story === null) {
            throw new NotFoundHttpException('The block this variant was made from has no stories any more.');
        }

        return $this->asJson([
            // Named and dated: a developer gets these from many editors, often
            // several from one. The date is the day the variant was saved.
            'snippet' => UserStoryBuilder::snippet(
                UserStoryBuilder::attributedTitle(
                    $record['title'],
                    Craft::$app->getUser()->getIdentity()?->getName(),
                    $this->savedOn($record['dateCreated']),
                ),
                $story->args,
                UserStoryBuilder::supportsInlineComments()
                    ? $component->title . ' · ' . $component->storyFilePath
                    : null,
            ),
            'file' => $component->storyFilePath,
        ]);
    }

    private function savedOn(string $dateCreated): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($dateCreated !== '' ? $dateCreated : 'now');
        } catch (\Exception) {
            return new \DateTimeImmutable();
        }
    }

    private function component(string $id): ComponentDefinition
    {
        $component = Plugin::getInstance()->getRepository()->getById($id);
        if ($component === null) {
            throw new BadRequestHttpException('Component not found.');
        }

        return $component;
    }

    private function binding(ComponentDefinition $component): ?AdapterBinding
    {
        $bindings = Plugin::getInstance()->getContractInspector()->bindings(
            [$component],
            Craft::$app->getPath()->getSiteTemplatesPath(),
        );

        return $bindings[$component->id] ?? null;
    }
}

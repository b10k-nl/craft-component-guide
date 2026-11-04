<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\helpers\ClientReview;
use b10k\componentguide\helpers\StoryControls;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\Plugin;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Client review, from the component page: approve a story as written, or ask
 * for changes — and the developer's way to take those decisions from staging
 * to the story files. Answers nothing while client review is off.
 */
class ReviewsController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }
        $this->requireCpRequest();
        if (!Plugin::canAccess()) {
            throw new \yii\web\ForbiddenHttpException('User is not permitted to perform this action.');
        }
        if (!Plugin::getInstance()->clientReviewEnabled()) {
            throw new NotFoundHttpException('Client review is off.');
        }
        return true;
    }

    /**
     * Records the signed-in reviewer's decision on a story. An approval is of
     * the story as written; a request for changes carries the values on
     * screen, so the developer sees what the reviewer tried in Controls.
     */
    public function actionDecide(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_REVIEW);

        $plugin = Plugin::getInstance();
        $component = $this->component((string)$this->request->getRequiredBodyParam('componentId'));
        $story = $component->getStory((string)$this->request->getRequiredBodyParam('storyId'));
        if ($story === null) {
            throw new NotFoundHttpException('Story not found.');
        }

        try {
            $overrides = StoryControls::decodeOverrides((string)$this->request->getBodyParam('args', ''));
        } catch (\InvalidArgumentException $e) {
            throw new BadRequestHttpException($e->getMessage(), 0, $e);
        }

        $decision = (string)$this->request->getRequiredBodyParam('decision');
        $args = StoryControls::withOverrides($story, $overrides)->args;
        if ($decision === ClientReview::APPROVED && in_array($component->status, ['draft', 'deprecated'], true)) {
            return $this->asFailure(Craft::t('component-guide', 'A draft or deprecated block cannot be approved yet. Ask for changes instead, or wait until it is marked stable.'));
        }
        if ($decision === ClientReview::APPROVED && !ClientReview::sameArgs($args, $story->args)) {
            return $this->asFailure(Craft::t('component-guide', 'Only the story as written can be approved. Reset the values to approve it, or send them with a request for changes.'));
        }

        try {
            $review = $plugin->getClientReviews()->decide(
                $component->id,
                $story->id,
                $story->title,
                $decision,
                $args,
                (string)$this->request->getBodyParam('comment', ''),
            );
        } catch (InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asJson([
            'review' => $review,
            'message' => $review['decision'] === ClientReview::APPROVED
                ? Craft::t('component-guide', 'Approved. Everyone with access sees it on this page.')
                : Craft::t('component-guide', 'Sent. Everyone with access sees your request on this page.'),
        ]);
    }

    /**
     * Undoes the signed-in user's decision in the seconds after the click.
     */
    public function actionUndo(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_REVIEW);

        if (!Plugin::getInstance()->getClientReviews()->undo((string)$this->request->getRequiredBodyParam('id'))) {
            return $this->asFailure(Craft::t('component-guide', 'Too late to undo: the decision stays. Make a new one if it was a mistake.'));
        }

        return $this->asJson(['message' => Craft::t('component-guide', 'Undone.')]);
    }

    /**
     * Clears the review history of one component (`componentId`), or of all.
     * The developer's own trial runs, before the client starts. Needs the
     * clear permission (admins have it) and the setting.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_CLEAR_REVIEWS);
        if (!Plugin::getInstance()->getSettings()->isOn('enableReviewClearing')) {
            throw new \yii\web\ForbiddenHttpException('Clearing the review history is turned off in the plugin settings.');
        }

        $componentId = $this->request->getBodyParam('componentId');
        $componentId = is_string($componentId) && $componentId !== '' ? $this->component($componentId)->id : null;
        $user = Craft::$app->getUser()->getIdentity();
        $removed = Plugin::getInstance()->getClientReviews()->clear($componentId, (string)($user->username ?? 'unknown'));

        return $this->asJson([
            'removed' => $removed,
            'message' => Craft::t('component-guide', '{count, plural, =0{Nothing to clear.} one{# decision cleared.} other{# decisions cleared.}}', ['count' => $removed]),
        ]);
    }

    /**
     * The developer marks a request for changes as fixed — or takes the mark
     * back. Needed only when the fix is outside the block's own template; a
     * change to the template is noticed by itself.
     */
    public function actionMarkFixed(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        // The developer's act, like writing the story files.
        $this->requireAdmin(false);

        $id = (string)$this->request->getRequiredBodyParam('id');
        $fixed = (bool)$this->request->getBodyParam('fixed', true);
        if (!Plugin::getInstance()->getClientReviews()->markFixed($id, $fixed)) {
            throw new NotFoundHttpException('Request for changes not found.');
        }

        return $this->asJson([
            'fixed' => $fixed,
            'message' => $fixed
                ? Craft::t('component-guide', 'Marked as fixed. The reviewer sees that the block is ready to check again.')
                : Craft::t('component-guide', 'The fixed mark is removed.'),
        ]);
    }

    /**
     * Every review on this site as one file — the trip from staging to where
     * the story files are written (`php craft component-guide/reviews/import`).
     */
    public function actionExport(): Response
    {
        // Every client's decisions with their names, out of the site: an
        // admin's call, like any other export of project data.
        $this->requireAdmin(false);

        $reviews = Plugin::getInstance()->getClientReviews()->all();
        $host = (string)(parse_url(UrlHelper::siteUrl(), PHP_URL_HOST) ?: 'site');
        $now = new \DateTimeImmutable();

        // The reviewer's user id means nothing on another install.
        $reviews = array_map(static function(array $review): array {
            unset($review['userId']);
            return $review;
        }, $reviews);

        Plugin::getInstance()->getClientReviews()->markExported($now);

        return Craft::$app->getResponse()->sendContentAsFile(
            // Without CRAFT_ENVIRONMENT, Craft names the environment after
            // the host: nothing to add to the site then.
            ClientReview::export($reviews, $host, $now, Craft::$app->env !== $host ? Craft::$app->env : null),
            sprintf('component-guide-reviews-%s-%s.json', preg_replace('/[^a-z0-9.-]+/i', '-', $host), $now->format('Y-m-d')),
            ['mimeType' => 'application/json'],
        );
    }

    private function component(string $id): ComponentDefinition
    {
        $component = Plugin::getInstance()->getRepository()->getById($id);
        if ($component === null) {
            throw new BadRequestHttpException('Component not found.');
        }

        return $component;
    }
}

<?php

namespace b10k\componentguide\controllers;

use b10k\componentguide\helpers\Prefill;
use b10k\componentguide\helpers\UserStoryBuilder;
use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;
use b10k\componentguide\services\GalleryMatcher;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * Feeds the Matrix block picker (see web/js/picker.js).
 *
 * Returns the component catalog keyed for entry-type matching: a component
 * whose template base name equals a Matrix entry type handle (e.g.
 * `statsBar.twig` ↔ handle `statsBar`) becomes that block's gallery card.
 *
 * Cards only ever show states an editor can actually reproduce. A story that
 * needs a field the entry type does not have renders beautifully and is a
 * promise nobody can keep, and the gallery is exactly where an editor would be
 * given it.
 */
class PickerController extends Controller
{
    /**
     * Previews in the editors' gallery leave out what is for the developer —
     * the “this preview is incomplete” note (see preview/document.twig).
     */
    private const FOR_GALLERY = ['for' => 'gallery'];

    /**
     * The gallery's preview parameters: for editors, and versioned so the
     * browser keeps a preview between openings until something it is made
     * from changes (PreviewRenderer::version()).
     *
     * @return array<string, string>
     */
    private function galleryParams(): array
    {
        return self::FOR_GALLERY + ['v' => Plugin::getInstance()->getPreviewRenderer()->version()];
    }

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
        return true;
    }

    public function actionMap(): Response
    {
        $plugin = Plugin::getInstance();
        $pro = $plugin->isPro();
        $matcher = $plugin->getGalleryMatcher();
        $components = $plugin->getRepository()->getAll();
        $templatesRoot = Craft::$app->getPath()->getSiteTemplatesPath();

        $inspector = $plugin->getContractInspector();
        $bindings = $inspector->bindings($components, $templatesRoot);
        $producible = $inspector->producibleStories($components, $templatesRoot);
        // Where the client review of each story stands — only with client
        // review on; read once for the whole gallery.
        $reviews = $plugin->clientReviewEnabled() ? $plugin->getClientReviews()->states() : [];

        $catalog = [];

        foreach ($components as $component) {
            // Same rule the index reports, from the same place: no entry type
            // to attach to, or no story to build a card from, and the gallery
            // has nothing to show. An index that disagreed with the gallery
            // would be worse than one that stayed quiet.
            if (!$matcher->appearsInGallery($component)) {
                continue;
            }

            $stories = $this->stories(
                $component,
                $bindings[$component->id] ?? null,
                $producible[$component->id] ?? [],
                $pro,
                $reviews[$component->id]['stories'] ?? [],
            );

            if ($stories === []) {
                continue;
            }

            $binding = $bindings[$component->id] ?? null;
            $editable = $binding !== null
                ? UserStoryBuilder::editableFields($binding, $component->stories)
                : [];

            $catalog[] = [
                // What the variant endpoints are asked about.
                'id' => $component->id,
                // `name` is the template base name, kept for search and
                // debugging; `matchKey` is what the gallery looks components up
                // by, computed here so the rule lives in PHP only.
                'name' => $component->name,
                'matchKey' => GalleryMatcher::matchKey($component->name),
                'title' => $component->title,
                'description' => $component->description,
                'status' => $component->status,
                'group' => $component->effectiveGroup(),
                // The signed-in editor's own variants follow the developer's
                // stories, so a card's default state is never one of them.
                'stories' => array_merge($stories, $this->mine($component, $binding, $editable, $pro)),
                // Saving a variant from a block: what it is based on, and which
                // fields of the block it can keep (field handle => argument).
                'baseStoryId' => $stories[0]['id'],
                'editableFields' => (object)$editable,
                // The first reproducible state is the default: what the card
                // shows, and what a plain click adds.
                'prefill' => $stories[0]['prefill'],
                'previewUrl' => $stories[0]['previewUrl'],
                'detailUrl' => UrlHelper::cpUrl("component-guide/components/{$component->id}"),
            ];
        }

        return $this->asJson([
            'pro' => $pro,
            'components' => $catalog,
            // Cards/Index-mode Matrix fields expose entry types as numeric IDs
            // (Craft.NestedElementManager settings.createAttributes.typeId),
            // never handles — so the gallery needs this lookup to match a
            // type to its component.
            'entryTypes' => array_map(
                static fn(array $type): array => $type + ['matchKey' => GalleryMatcher::matchKey($type['handle'])],
                $matcher->entryTypes(),
            ),
        ]);
    }

    /**
     * The states worth offering an editor, in story order.
     *
     * @param string[] $producibleIds
     * @param bool $pro Whether to include the field values a click would apply.
     * @param array<string, array<string, mixed>> $reviews ClientReview::state() by story id.
     * @return list<array{id: string, title: string, description: string|null, previewUrl: string, prefill: array<string, mixed>|null, review: array{state: string, label: string, note: string}|null}>
     */
    private function stories(
        ComponentDefinition $component,
        ?AdapterBinding $binding,
        array $producibleIds,
        bool $pro,
        array $reviews = [],
    ): array {
        $stories = [];

        foreach ($component->stories as $story) {
            if (!in_array($story->id, $producibleIds, true)) {
                continue;
            }

            $stories[] = [
                'id' => $story->id,
                'title' => $story->title,
                // Shown on the story's own card when the gallery splits
                // variants into cards.
                'description' => $story->description,
                'previewUrl' => Plugin::previewUrl($component->id, $story->id, $this->galleryParams()),
                // Lite sees the card and the preview; it does not receive the
                // field values, because filling the block in is the paid half.
                // Held back on the server, never merely hidden in the browser.
                'prefill' => $pro ? ($this->prefill($component, $story, $binding) ?: null) : null,
                'review' => $this->review($reviews[$story->id] ?? null),
            ];
        }

        // Nothing reproducible at all. The block can still be added, so the card
        // stays — dropping it would hide a block an editor is entitled to use.
        // The index badge is where the developer is told why the preview lies.
        if ($stories === [] && isset($component->stories[0])) {
            $first = $component->stories[0];
            $stories[] = [
                'id' => $first->id,
                'title' => $first->title,
                'description' => $first->description,
                'previewUrl' => Plugin::previewUrl($component->id, $first->id, $this->galleryParams()),
                'prefill' => $pro ? ($this->prefill($component, $first, $binding) ?: null) : null,
                'review' => $this->review($reviews[$first->id] ?? null),
            ];
        }

        return $stories;
    }

    /**
     * Where the client review of a story stands, for the editor about to add
     * it: approved, or changes requested. Nothing while it is to review — on
     * most sites that is every block, and a label on every card is noise.
     *
     * @param array<string, mixed>|null $state ClientReview::state()
     * @return array{state: string, label: string, note: string}|null
     */
    private function review(?array $state): ?array
    {
        return match ($state['state'] ?? null) {
            'approved' => [
                'state' => 'approved',
                'label' => Craft::t('component-guide', 'approved'),
                'note' => Craft::t('component-guide', 'Approved by {name}, {date}', ['name' => $state['by'], 'date' => Craft::$app->getFormatter()->asDate(new \DateTime($state['date'], new \DateTimeZone(Craft::$app->getTimeZone())), 'short')]),
            ],
            'changes' => [
                'state' => 'changes',
                'label' => Craft::t('component-guide', 'changes requested'),
                'note' => Craft::t('component-guide', 'Changes were asked for on this block. It may still change — check with your developer before relying on it.'),
            ],
            default => null,
        };
    }

    /**
     * The signed-in user's own variants of a component, shaped like stories.
     * Their values are field handles already, so they are the prefill as they
     * stand — what the editor typed into the block is what a click puts back.
     *
     * @param array<string, string> $editable
     * @return list<array<string, mixed>>
     */
    private function mine(ComponentDefinition $component, ?AdapterBinding $binding, array $editable, bool $pro): array
    {
        $userStories = Plugin::getInstance()->getUserStories();
        $mine = [];

        foreach ($userStories->forComponent($component->id) as $record) {
            if ($userStories->story($component, $record, $binding) === null) {
                continue;
            }

            // A variant keeps only simple fields; rich text and the like come
            // from the story it was based on — in the preview, and so also in
            // the block a click adds. Left out of the prefill, the card would
            // show the base story's body text and the block arrive without it.
            $base = $component->getStory($record['baseStoryId']) ?? $component->stories[0];
            $prefill = $pro
                ? array_merge(
                    $this->prefill($component, $base, $binding),
                    UserStoryBuilder::cleanValues($record['values'], $editable),
                )
                : [];

            $mine[] = [
                'id' => 'mine-' . $record['id'],
                'userStoryId' => $record['id'],
                'title' => $record['title'],
                'description' => null,
                'previewUrl' => Plugin::userStoryPreviewUrl($component->id, $record['id'], self::FOR_GALLERY),
                'prefill' => $prefill ?: null,
                'mine' => true,
            ];
        }

        return $mine;
    }

    /**
     * The block's starting content — see Prefill.
     *
     * @return array<string, mixed>
     */
    private function prefill(
        ComponentDefinition $component,
        StoryDefinition $story,
        ?AdapterBinding $binding,
    ): array {
        return Prefill::fields($component, $story, $binding);
    }
}

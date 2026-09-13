<?php

namespace b10k\componentguide\controllers;

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

    public function actionMap(): Response
    {
        $plugin = Plugin::getInstance();
        $matcher = $plugin->getGalleryMatcher();
        $components = $plugin->getRepository()->getAll();
        $templatesRoot = Craft::$app->getPath()->getSiteTemplatesPath();

        $inspector = $plugin->getContractInspector();
        $bindings = $inspector->bindings($components, $templatesRoot);
        $producible = $inspector->producibleStories($components, $templatesRoot);

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
            );

            if ($stories === []) {
                continue;
            }

            $catalog[] = [
                // `name` is the template base name, kept for search and
                // debugging; `matchKey` is what the gallery looks components up
                // by, computed here so the rule lives in PHP only.
                'name' => $component->name,
                'matchKey' => GalleryMatcher::matchKey($component->name),
                'title' => $component->title,
                'description' => $component->description,
                'status' => $component->status,
                'group' => $component->effectiveGroup(),
                'stories' => $stories,
                // The first reproducible state is the default: what the card
                // shows, and what a plain click adds.
                'prefill' => $stories[0]['prefill'],
                'previewUrl' => $stories[0]['previewUrl'],
                'detailUrl' => UrlHelper::cpUrl("component-guide/components/{$component->id}"),
            ];
        }

        return $this->asJson([
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
     * @return list<array{id: string, title: string, previewUrl: string, prefill: array<string, mixed>|null}>
     */
    private function stories(
        ComponentDefinition $component,
        ?AdapterBinding $binding,
        array $producibleIds,
    ): array {
        $stories = [];

        foreach ($component->stories as $story) {
            if (!in_array($story->id, $producibleIds, true)) {
                continue;
            }

            $stories[] = [
                'id' => $story->id,
                'title' => $story->title,
                'previewUrl' => Plugin::previewUrl($component->id, $story->id),
                'prefill' => $this->prefill($component, $story, $binding) ?: null,
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
                'previewUrl' => Plugin::previewUrl($component->id, $first->id),
                'prefill' => $this->prefill($component, $first, $binding) ?: null,
            ];
        }

        return $stories;
    }

    /**
     * Scalar args become the block's starting content, keyed by the field they
     * actually belong to.
     *
     * The adapter states that mapping; until 1.4.0 the browser guessed it from
     * a naming convention of our own invention, so a project that named its
     * field anything else got an empty block and no explanation.
     *
     * @return array<string, mixed>
     */
    private function prefill(
        ComponentDefinition $component,
        StoryDefinition $story,
        ?AdapterBinding $binding,
    ): array {
        // Resolve with the same seed the preview uses, so the block a click
        // adds contains exactly the text its gallery card shows.
        $resolved = Plugin::getInstance()->getPlaceholderResolver()
            ->resolveArgs($story->args, $component->id . '/' . $story->id);

        $prefill = [];

        foreach ($resolved as $key => $value) {
            // An argument built from two fields has no single target, and
            // picking one of them is the guessing this replaced.
            $handle = $binding !== null ? $binding->fieldFor((string)$key) : (string)$key;
            if ($handle === null) {
                continue;
            }

            // Rich and nested values can't be mapped onto fields reliably, and
            // data-URI placeholders would paste a whole image into a text box.
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $prefill[$handle] = $value;
            } elseif (is_string($value)
                && $value !== ''
                && mb_strlen($value) <= 500
                && !str_starts_with($value, 'data:')
            ) {
                $prefill[$handle] = $value;
            }
        }

        return $prefill;
    }
}

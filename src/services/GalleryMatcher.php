<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use Craft;
use yii\base\Component;

/**
 * The one place that answers “does this component reach editors?”.
 *
 * The blocks gallery (web/js/picker.js) turns a component into a card when a
 * Matrix entry type carries its template base name as a handle —
 * `_blocks/hero.twig` ↔ entry type `hero`. The picker applies that rule in the
 * browser; the control panel needs the same answer on the server so the index
 * can report it.
 *
 * Keeping the rule in one service is the point: an index that disagreed with
 * the gallery would be worse than an index that stayed silent.
 */
class GalleryMatcher extends Component
{
    /** @var array<string, string>|null Entry-type handle => entry-type name. */
    private ?array $handles = null;

    /**
     * Mirrors the `addable` test in web/js/picker.js: an explicit non-stable
     * status is the developer's own “not ready for editors” marker, and the
     * gallery renders those as disabled cards.
     */
    public function isAddable(ComponentDefinition $component): bool
    {
        return in_array($component->status, [null, '', 'stable'], true);
    }

    /**
     * Name of the entry type this component becomes a gallery card for, or
     * null when no entry type carries its handle. Matching is exact and
     * case-sensitive, exactly as the picker does it (`comps[item.type]`).
     */
    public function matchedEntryType(ComponentDefinition $component): ?string
    {
        return $this->entryTypeHandles()[$component->name] ?? null;
    }

    /**
     * Whether the gallery shows a card for this component at all — matched to
     * an entry type, and carrying at least one story the card can be built
     * from.
     *
     * The story requirement is not a nicety. A story *file* that fails to parse
     * leaves the component documented (the file exists, which is what stops the
     * scaffolder overwriting it) but with nothing in it, and a card built from
     * nothing is an empty box with no preview and no prefill — the exact thing
     * the gallery exists to replace. Silently offering one is worse than not
     * offering the component at all, and the developer already has the scan
     * error on the index telling them why.
     */
    public function appearsInGallery(ComponentDefinition $component): bool
    {
        return $component->storyCount() > 0
            && $this->matchedEntryType($component) !== null;
    }

    /**
     * Whether an editor can add this component from the gallery *and* see what
     * they are adding: matched to an entry type (so it appears at all), stable
     * (so it is not disabled) and documented (so the card carries a preview
     * and prefill instead of an empty box).
     *
     * An unmatched or story-less template is not a failure — plenty of
     * components are never page-builder blocks. This counts the handoffs that
     * completed, not the ones that “should” have.
     */
    public function isReadyForEditors(ComponentDefinition $component): bool
    {
        return $this->appearsInGallery($component)
            && $this->isAddable($component);
    }

    /**
     * @param ComponentDefinition[] $components
     */
    public function countReadyForEditors(array $components): int
    {
        return count(array_filter($components, $this->isReadyForEditors(...)));
    }

    /**
     * @param ComponentDefinition[] $components
     */
    public function countMatched(array $components): int
    {
        return count(array_filter(
            $components,
            fn(ComponentDefinition $c): bool => $this->matchedEntryType($c) !== null,
        ));
    }

    /**
     * Component ID => matched entry-type name, for every component the gallery
     * knows about at all — disabled ones included, since a non-stable status
     * still renders a (disabled) card and the index decides what to say about
     * it. Components the gallery skips entirely are absent, so the index cannot
     * claim “in gallery” for something an editor will never see.
     *
     * @param ComponentDefinition[] $components
     * @return array<string, string>
     */
    public function entryTypeNames(array $components): array
    {
        $names = [];
        foreach ($components as $component) {
            if (!$this->appearsInGallery($component)) {
                continue;
            }
            $names[$component->id] = (string)$this->matchedEntryType($component);
        }

        return $names;
    }

    /**
     * Every entry type, as the picker needs it: Cards and Index fields expose
     * types as numeric IDs only, so the gallery has to look the handle up by ID.
     *
     * @return array<int, array{id: int, handle: string, name: string}>
     */
    public function entryTypes(): array
    {
        $service = Craft::$app->getEntries();
        if (!method_exists($service, 'getAllEntryTypes')) {
            return [];
        }

        $types = [];
        foreach ($service->getAllEntryTypes() as $type) {
            $types[] = [
                'id' => (int)$type->id,
                'handle' => (string)$type->handle,
                'name' => (string)$type->name,
            ];
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function entryTypeHandles(): array
    {
        if ($this->handles === null) {
            $this->handles = [];
            foreach ($this->entryTypes() as $type) {
                $this->handles[$type['handle']] = $type['name'];
            }
        }

        return $this->handles;
    }
}

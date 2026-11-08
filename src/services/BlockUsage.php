<?php

namespace b10k\componentguide\services;

use b10k\componentguide\helpers\BlockOwners;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\Plugin;
use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Entry;
use yii\base\Component;

/**
 * Where a block is used: the pages — and any other element an editor opens —
 * that hold a block of the component's entry type, in a Matrix field, in
 * CKEditor, or inside another block. For “Used on” on the component's page.
 *
 * Only for a component matched to an entry type (the gallery's matching):
 * a shared partial is used by other templates, not by entries.
 */
class BlockUsage extends Component
{
    /** Blocks read at most; past it the list says it is counted from these. */
    public const LIMIT = 500;

    /** Blocks read at most for the used / unused filter, all types together. */
    public const MAP_LIMIT = 5000;

    /** @var array<string, 'used'|'unused'>|null */
    private ?array $usage = null;

    /**
     * Whether each component matched to an entry type is on any page — for
     * the sidebar's used / unused filter. Components no entry type matches
     * are left out: “unused” would say nothing about a shared partial.
     *
     * All the blocks in one query and their owners a level at a time, shared
     * across types: a handful of queries for the whole library.
     *
     * @return array<string, 'used'|'unused'> component id => state
     */
    public function usage(): array
    {
        if ($this->usage !== null) {
            return $this->usage;
        }

        $plugin = Plugin::getInstance();
        $matcher = $plugin->getGalleryMatcher();
        $entries = Craft::$app->getEntries();
        /** @var array<int, list<string>> $byType entry type id => component ids */
        $byType = [];
        foreach ($plugin->getRepository()->getAll() as $component) {
            $handle = $matcher->matchedEntryTypeHandle($component);
            $entryType = $handle !== null ? $entries->getEntryTypeByHandle($handle) : null;
            if ($entryType !== null) {
                $byType[(int)$entryType->id][] = $component->id;
            }
        }
        if ($byType === []) {
            return $this->usage = [];
        }

        $blocks = Entry::find()
            ->typeId(array_keys($byType))
            ->status(null)
            ->site('*')
            ->unique()
            ->limit(self::MAP_LIMIT + 1)
            ->all();
        $truncated = count($blocks) > self::MAP_LIMIT;

        // Owners fetched once, whichever type asks: misses are remembered too.
        /** @var array<int, ElementInterface|null> $known */
        $known = [];
        $load = function(array $ids) use (&$known): array {
            $missing = array_values(array_diff($ids, array_keys($known)));
            if ($missing !== []) {
                $found = $this->owners($missing);
                foreach ($missing as $id) {
                    $known[$id] = $found[$id] ?? null;
                }
            }
            return array_filter(array_intersect_key($known, array_flip($ids)));
        };
        BlockOwners::roots($blocks, $load);

        $blocksByType = [];
        foreach ($blocks as $block) {
            $blocksByType[(int)$block->typeId][] = $block;
        }

        $usage = [];
        foreach ($byType as $typeId => $componentIds) {
            $typeBlocks = $blocksByType[$typeId] ?? [];
            $used = $typeBlocks !== []
                ? BlockOwners::roots($typeBlocks, $load) !== []
                // Past the limit a type may simply not have been read.
                : $truncated && Entry::find()->typeId($typeId)->status(null)->exists();
            foreach ($componentIds as $id) {
                $usage[$id] = $used ? 'used' : 'unused';
            }
        }

        return $this->usage = $usage;
    }

    /**
     * @return array{entryType: string, pages: list<array{id: int, siteId: int, type: class-string, title: string, context: string, status: string|null, url: string|null, count: int, disabled: int}>, blocks: int, truncated: bool, notShown: int}|null
     *     `context` is the section (or element type) and the site, where they add to the title; may be empty.
     *     `status` is the page's; `disabled` counts its blocks of this type that are disabled.
     *     Null when the component is not matched to an entry type.
     */
    public function of(ComponentDefinition $component): ?array
    {
        $handle = Plugin::getInstance()->getGalleryMatcher()->matchedEntryTypeHandle($component);
        $entryType = $handle !== null ? Craft::$app->getEntries()->getEntryTypeByHandle($handle) : null;
        if ($entryType === null) {
            return null;
        }

        $blocks = Entry::find()
            ->typeId($entryType->id)
            ->status(null)
            ->site('*')
            ->unique()
            ->limit(self::LIMIT + 1)
            ->all();
        $truncated = count($blocks) > self::LIMIT;
        if ($truncated) {
            $blocks = array_slice($blocks, 0, self::LIMIT);
        }

        $roots = BlockOwners::roots(
            $blocks,
            $this->owners(...),
            static fn(mixed $block): bool => $block instanceof ElementInterface && $block->getStatus() === Element::STATUS_DISABLED,
        );

        // Only what this person may open: the guide is not a way round
        // section permissions.
        $user = Craft::$app->getUser()->getIdentity();
        $elements = Craft::$app->getElements();
        $multiSite = Craft::$app->getIsMultiSite();
        $pages = [];
        foreach ($roots as ['root' => $root, 'count' => $count, 'disabled' => $disabled]) {
            if (!$root instanceof ElementInterface || $user === null || !$elements->canView($root, $user)) {
                continue;
            }
            // The name the control panel gives it: a single without a title
            // field is called after its section (“Home”), not “Entry 2”.
            $title = trim($root->getUiLabel());
            if ($title === '') {
                $title = (string)$root;
            }
            $section = $root instanceof Entry && $root->getSection() !== null
                ? (string)$root->getSection()->name
                : $root::displayName();
            $context = array_values(array_filter([
                $section !== $title ? $section : null,
                $multiSite ? $root->getSite()->getName() : null,
            ]));
            $pages[] = [
                'id' => (int)$root->id,
                'siteId' => (int)$root->siteId,
                'type' => $root::class,
                'title' => $title,
                'context' => implode(' · ', $context),
                'status' => $root->getStatus(),
                'url' => $root->getCpEditUrl(),
                'count' => $count,
                'disabled' => $disabled,
            ];
        }
        usort($pages, static fn(array $a, array $b): int => strnatcasecmp($a['title'], $b['title']));

        return [
            'entryType' => (string)$entryType->name,
            'pages' => $pages,
            'blocks' => count($blocks),
            'truncated' => $truncated,
            'notShown' => count($roots) - count($pages),
        ];
    }

    /**
     * Owners by id, of whatever element type they are — drafts and revisions
     * included, so the walk can recognise them and leave them out.
     *
     * @param list<int> $ids
     * @return array<int, ElementInterface>
     */
    private function owners(array $ids): array
    {
        $found = [];
        foreach (Craft::$app->getElements()->getElementTypesByIds($ids) as $type) {
            /** @var class-string<ElementInterface> $type */
            $query = $type::find()
                ->id($ids)
                ->status(null)
                ->site('*')
                ->unique()
                ->drafts(null)
                ->provisionalDrafts(null)
                ->revisions(null);
            foreach ($query->all() as $element) {
                $found[(int)$element->id] = $element;
            }
        }

        return $found;
    }
}

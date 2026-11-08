<?php

namespace b10k\componentguide\helpers;

/**
 * Where blocks live: from nested entries (a Matrix block, a CKEditor nested
 * entry, a block inside a block) up to the element an editor opens — a page,
 * a service, a doctor; a category or a global set as well.
 *
 * Kept free of Craft so the walk can be tested: elements are read through
 * their public properties (`id`, `fieldId`, `primaryOwnerId`) and the two
 * methods every Craft element has (`getIsDraft()`, `getIsRevision()`), and
 * owners are fetched by the callable the caller passes in, a level at a time.
 */
final class BlockOwners
{
    /** A block inside a block inside a block… Stops a cycle in bad data. */
    public const MAX_DEPTH = 8;

    /**
     * Groups blocks by the element they are on. Blocks on a draft or a
     * revision, and blocks whose owner no longer exists, are left out: they
     * are not where the block is used.
     *
     * @param iterable<mixed> $blocks Nested entries of one entry type.
     * @param callable(list<int>): array<int, mixed> $loadByIds Owners by id; missing ids are left out.
     * @param (callable(mixed): bool)|null $isDisabled Whether a block is disabled on its page.
     * @return list<array{root: mixed, count: int, disabled: int}> In the order the roots were first met.
     */
    public static function roots(iterable $blocks, callable $loadByIds, ?callable $isDisabled = null): array
    {
        /** @var array<int, mixed> $current block index => element reached so far */
        $current = [];
        /** @var array<int, bool> $off block index => disabled */
        $off = [];
        foreach ($blocks as $block) {
            $off[count($current)] = $isDisabled !== null && $isDisabled($block);
            $current[] = $block;
        }

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $wanted = [];
            foreach ($current as $element) {
                if (self::isNested($element)) {
                    $wanted[(int)$element->primaryOwnerId] = true;
                }
            }
            if ($wanted === []) {
                break;
            }

            $owners = $loadByIds(array_keys($wanted));
            foreach ($current as $i => $element) {
                if (!self::isNested($element)) {
                    continue;
                }
                $owner = $owners[(int)$element->primaryOwnerId] ?? null;
                if ($owner === null) {
                    unset($current[$i]);
                } else {
                    $current[$i] = $owner;
                }
            }
        }

        $grouped = [];
        foreach ($current as $i => $root) {
            if (self::isNested($root) || $root->getIsDraft() || $root->getIsRevision()) {
                continue;
            }
            $id = (int)$root->id;
            $grouped[$id] ??= ['root' => $root, 'count' => 0, 'disabled' => 0];
            $grouped[$id]['count']++;
            if ($off[$i]) {
                $grouped[$id]['disabled']++;
            }
        }

        return array_values($grouped);
    }

    /**
     * A nested element: it has a field and an owner. Only entries are nested
     * in Craft 5; anything else is where a walk ends.
     */
    public static function isNested(mixed $element): bool
    {
        return isset($element->fieldId, $element->primaryOwnerId)
            && $element->fieldId !== null
            && $element->primaryOwnerId !== null;
    }
}

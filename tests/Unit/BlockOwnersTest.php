<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\BlockOwners;
use PHPUnit\Framework\TestCase;

/**
 * “Used on”: from blocks to the pages they are on.
 */
class BlockOwnersTest extends TestCase
{
    public function testBlocksAreGroupedByThePageTheyAreOn(): void
    {
        $page = $this->element(1);
        $other = $this->element(2);
        $blocks = [$this->element(10, 1), $this->element(11, 1), $this->element(12, 2)];

        $roots = BlockOwners::roots($blocks, $this->loader([1 => $page, 2 => $other]));

        $this->assertSame([[1, 2], [2, 1]], $this->summary($roots));
    }

    public function testABlockInsideABlockBelongsToThePage(): void
    {
        $page = $this->element(1);
        $row = $this->element(20, 1);
        $calls = 0;
        $load = function (array $ids) use ($page, $row, &$calls): array {
            $calls++;
            return array_intersect_key([1 => $page, 20 => $row], array_flip($ids));
        };

        $roots = BlockOwners::roots([$this->element(30, 20), $this->element(31, 20)], $load);

        $this->assertSame([[1, 2]], $this->summary($roots));
        $this->assertSame(2, $calls, 'a level at a time, not a block at a time');
    }

    public function testDraftsRevisionsAndMissingOwnersAreNotWhereABlockIsUsed(): void
    {
        $page = $this->element(1);
        $draft = $this->element(2, null, draft: true);
        $revision = $this->element(3, null, revision: true);
        $blocks = [$this->element(10, 1), $this->element(11, 2), $this->element(12, 3), $this->element(13, 99)];

        $roots = BlockOwners::roots($blocks, $this->loader([1 => $page, 2 => $draft, 3 => $revision]));

        $this->assertSame([[1, 1]], $this->summary($roots));
    }

    public function testACycleInBadDataEnds(): void
    {
        $a = $this->element(1, 2);
        $b = $this->element(2, 1);

        $this->assertSame([], BlockOwners::roots([$this->element(10, 1)], $this->loader([1 => $a, 2 => $b])));
    }

    private function element(int $id, ?int $ownerId = null, bool $draft = false, bool $revision = false): object
    {
        return new class($id, $ownerId, $draft, $revision) {
            public ?int $fieldId;
            public ?int $primaryOwnerId;

            public function __construct(public int $id, ?int $ownerId, private bool $draft, private bool $revision)
            {
                $this->fieldId = $ownerId !== null ? 5 : null;
                $this->primaryOwnerId = $ownerId;
            }

            public function getIsDraft(): bool
            {
                return $this->draft;
            }

            public function getIsRevision(): bool
            {
                return $this->revision;
            }
        };
    }

    /**
     * @param array<int, object> $elements
     */
    private function loader(array $elements): callable
    {
        return static fn(array $ids): array => array_intersect_key($elements, array_flip($ids));
    }

    /**
     * @param list<array{root: object, count: int, disabled: int}> $roots
     * @return list<array{0: int, 1: int}>
     */
    private function summary(array $roots): array
    {
        return array_map(static fn(array $r): array => [$r['root']->id, $r['count']], $roots);
    }

    public function testDisabledBlocksAreCountedApart(): void
    {
        $page = $this->element(1);
        $on = $this->element(10, 1);
        $off = $this->element(11, 1);

        $roots = BlockOwners::roots([$on, $off], $this->loader([1 => $page]), static fn(object $b): bool => $b === $off);

        $this->assertSame(2, $roots[0]['count']);
        $this->assertSame(1, $roots[0]['disabled'], 'two blocks on the page, one disabled');
    }
}

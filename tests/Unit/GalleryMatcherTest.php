<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\services\GalleryMatcher;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides whether a component reaches editors.
 *
 * `entryTypes()` is the only Craft-dependent seam, so the double overrides it
 * and everything below is plain data.
 */
class GalleryMatcherTest extends TestCase
{
    private function matcher(string ...$handles): GalleryMatcher
    {
        return new class($handles) extends GalleryMatcher {
            /** @param string[] $typeHandles */
            public function __construct(private array $typeHandles)
            {
                parent::__construct();
            }

            public function entryTypes(): array
            {
                $types = [];
                foreach ($this->typeHandles as $i => $handle) {
                    $types[] = ['id' => $i + 1, 'handle' => $handle, 'name' => ucfirst($handle)];
                }

                return $types;
            }
        };
    }

    private function component(string $name, ?string $status, int $storyCount): ComponentDefinition
    {
        $stories = [];
        for ($i = 0; $i < $storyCount; $i++) {
            $stories[] = new StoryDefinition(id: "s$i", name: "S$i", title: "S$i");
        }

        return new ComponentDefinition(
            id: "c-$name",
            name: $name,
            title: ucfirst($name),
            templatePath: "_blocks/$name",
            absoluteTemplatePath: "/tmp/_blocks/$name.twig",
            status: $status,
            storyFilePath: "_blocks/$name.stories.twig",
            stories: $stories,
        );
    }

    public function testMatchesAnEntryTypeByTemplateBaseName(): void
    {
        $matcher = $this->matcher('hero');
        self::assertSame('Hero', $matcher->matchedEntryType($this->component('hero', 'stable', 1)));
        self::assertNull($matcher->matchedEntryType($this->component('teaser', 'stable', 1)));
    }

    public function testAppearsInGalleryNeedsBothAMatchAndAStory(): void
    {
        $matcher = $this->matcher('hero');
        self::assertTrue($matcher->appearsInGallery($this->component('hero', 'stable', 1)));
        self::assertFalse($matcher->appearsInGallery($this->component('teaser', 'stable', 1)));
    }

    /**
     * The bug this rule exists for: a story file that failed to parse leaves the
     * component documented but empty, and a card built from nothing is an empty
     * box in front of an editor.
     */
    public function testAComponentWhoseStoryFileParsedToNothingStaysOutOfTheGallery(): void
    {
        $matcher = $this->matcher('hero');
        $broken = $this->component('hero', null, 0);

        self::assertTrue($broken->isDocumented, 'the story file exists, so it counts as documented');
        self::assertFalse($matcher->appearsInGallery($broken));
        self::assertFalse($matcher->isReadyForEditors($broken));
        self::assertSame([], $matcher->entryTypeNames([$broken]));
    }

    public function testADisabledStatusStillAppearsButIsNotReady(): void
    {
        $matcher = $this->matcher('hero');
        $draft = $this->component('hero', 'draft', 2);

        self::assertTrue($matcher->appearsInGallery($draft), 'a draft renders as a disabled card');
        self::assertFalse($matcher->isAddable($draft));
        self::assertFalse($matcher->isReadyForEditors($draft));
        self::assertSame(['c-hero' => 'Hero'], $matcher->entryTypeNames([$draft]));
    }

    public function testNullAndEmptyStatusesAreAddable(): void
    {
        $matcher = $this->matcher('hero');
        self::assertTrue($matcher->isAddable($this->component('hero', null, 1)));
        self::assertTrue($matcher->isAddable($this->component('hero', '', 1)));
        self::assertTrue($matcher->isAddable($this->component('hero', 'stable', 1)));
        self::assertFalse($matcher->isAddable($this->component('hero', 'beta', 1)));
        self::assertFalse($matcher->isAddable($this->component('hero', 'deprecated', 1)));
    }

    public function testMatchingIsCaseSensitive(): void
    {
        $matcher = $this->matcher('heroBanner');
        self::assertNull($matcher->matchedEntryType($this->component('herobanner', 'stable', 1)));
        self::assertSame('HeroBanner', $matcher->matchedEntryType($this->component('heroBanner', 'stable', 1)));
    }

    public function testCountsOnlyTheHandoffsThatCompleted(): void
    {
        $matcher = $this->matcher('hero', 'teaser', 'quote');
        $components = [
            $this->component('hero', 'stable', 1),   // ready
            $this->component('teaser', 'draft', 1),  // matched, disabled
            $this->component('quote', null, 0),      // matched, story file broken
            $this->component('promo', 'stable', 1),  // no entry type
        ];

        self::assertSame(1, $matcher->countReadyForEditors($components));
        self::assertSame(
            ['c-hero' => 'Hero', 'c-teaser' => 'Teaser'],
            $matcher->entryTypeNames($components),
        );
    }
}

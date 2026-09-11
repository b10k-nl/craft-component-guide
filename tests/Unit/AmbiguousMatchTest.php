<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use b10k\componentguide\services\ComponentScanner;
use b10k\componentguide\services\StoryParser;
use PHPUnit\Framework\TestCase;

/**
 * The detection half of ambiguous matching: given a set of components, which
 * of them collide once entry-type matching normalises their names.
 *
 * The decision half — what a flagged component is then allowed to do — lives in
 * GalleryMatcherTest.
 */
class AmbiguousMatchTest extends TestCase
{
    private function component(string $name): ComponentDefinition
    {
        return new ComponentDefinition(
            id: 'c-' . strtolower($name),
            name: $name,
            title: $name,
            templatePath: "_blocks/$name",
            absoluteTemplatePath: "/tmp/_blocks/$name.twig",
        );
    }

    /** @param ComponentDefinition[] $components */
    private function flag(array $components): void
    {
        (new ComponentScanner(new StoryParser()))->flagAmbiguousMatches($components);
    }

    private function isFlagged(ComponentDefinition $component): bool
    {
        foreach ($component->errors as $error) {
            if ($error->type === ScanError::AMBIGUOUS_MATCH) {
                return true;
            }
        }

        return false;
    }

    public function testDistinctNamesAreLeftAlone(): void
    {
        $components = [$this->component('hero'), $this->component('heroCard'), $this->component('teaser')];
        $this->flag($components);

        foreach ($components as $component) {
            self::assertFalse($this->isFlagged($component), $component->name);
        }
    }

    public function testTwoNamesThatDifferOnlyInSeparatorsAreBothFlagged(): void
    {
        $a = $this->component('hero-card');
        $b = $this->component('heroCard');
        $this->flag([$a, $b]);

        self::assertTrue($this->isFlagged($a));
        self::assertTrue($this->isFlagged($b));
    }

    /**
     * Both sides get named in both messages: whoever opens either card should
     * be able to see what it collides with without hunting.
     */
    public function testTheMessageNamesEveryTemplateInTheCollision(): void
    {
        $a = $this->component('_featured-story');
        $b = $this->component('featuredStory');
        $this->flag([$a, $b]);

        foreach ([$a, $b] as $component) {
            $message = $component->errors[0]->message;
            self::assertStringContainsString('_blocks/_featured-story', $message);
            self::assertStringContainsString('_blocks/featuredStory', $message);
        }
    }

    public function testThreeWayCollisionsFlagAllOfThem(): void
    {
        $components = [
            $this->component('cta-banner'),
            $this->component('ctaBanner'),
            $this->component('CTA_BANNER'),
        ];
        $this->flag($components);

        foreach ($components as $component) {
            self::assertTrue($this->isFlagged($component), $component->name);
        }
    }

    /**
     * Flagged even with no entry type in sight: the collision is a fact about
     * the two templates, and the day someone adds the block is not the day to
     * find out about it.
     */
    public function testCollisionsAreFlaggedWithoutConsultingEntryTypes(): void
    {
        $a = $this->component('nothing-like-a-block');
        $b = $this->component('nothingLikeABlock');
        $this->flag([$a, $b]);

        self::assertTrue($this->isFlagged($a));
        self::assertTrue($this->isFlagged($b));
    }
}

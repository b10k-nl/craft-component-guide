<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\OwnPreferences;
use b10k\componentguide\services\ClientReviews;
use b10k\componentguide\services\UserStories;
use PHPUnit\Framework\TestCase;

/**
 * Uninstalling takes our keys out of user preferences and leaves Craft's and
 * other plugins' alone.
 */
class OwnPreferencesTest extends TestCase
{
    public function testOnlyOurKeysAreRemoved(): void
    {
        [$kept, $changed] = OwnPreferences::strip([
            'language' => 'nl',
            'enableDebugToolbarForCp' => true,
            'componentGuide.stories' => [['id' => 'a']],
            'componentGuide.reviews' => [],
            'componentGuide.reviewsExported' => '2026-10-30T10:00:00+00:00',
            'otherPlugin.componentGuide.x' => 1,
        ]);

        $this->assertTrue($changed);
        $this->assertSame(['language' => 'nl', 'enableDebugToolbarForCp' => true, 'otherPlugin.componentGuide.x' => 1], $kept);
        $this->assertFalse(OwnPreferences::strip(['language' => 'nl'])[1], 'nothing of ours: nothing to write');
    }

    public function testEveryKeyWeWriteCarriesThePrefix(): void
    {
        foreach ([UserStories::PREFERENCE_KEY, ClientReviews::PREFERENCE_KEY, ClientReviews::EXPORTED_KEY] as $key) {
            $this->assertStringStartsWith(OwnPreferences::PREFIX, $key);
        }
    }
}

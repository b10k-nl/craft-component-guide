<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\mcp\ContractFindings;
use b10k\componentguide\mcp\McpBrief;
use b10k\componentguide\models\ScanError;
use PHPUnit\Framework\TestCase;

/**
 * What an agent on Craft's MCP server is told, and what the audit reports.
 */
class McpTest extends TestCase
{
    private function block(string $title, string $handle, array $stories, array $approved = []): array
    {
        return ['title' => $title, 'entryType' => $handle, 'group' => 'Heroes', 'stories' => $stories, 'approved' => $approved];
    }

    public function testReadyBlocksAreListedWithTheirEntryTypeAndStories(): void
    {
        $text = McpBrief::text([$this->block('Hero', 'hero', ['Light', 'Dark'])], 2, false);

        $this->assertStringContainsString('- Hero: entry type `hero`; stories: Light, Dark', $text);
        $this->assertStringContainsString('2 more blocks have an entry type but are not ready', $text);
        $this->assertStringNotContainsString('approved', $text);
        $this->assertStringContainsString('`' . McpBrief::CHECK_ID . '`', $text);
    }

    public function testApprovedStoriesAreMarkedOnlyWithClientReviewOn(): void
    {
        $blocks = [$this->block('Hero', 'hero', ['Light', 'Dark'], ['Dark'])];

        $this->assertStringContainsString('stories: Light, Dark (approved)', McpBrief::text($blocks, 0, true));
        $this->assertStringContainsString('stories: Light, Dark' . "\n", McpBrief::text($blocks, 0, false));
    }

    public function testALongLibraryIsCountedNotListed(): void
    {
        $blocks = [];
        for ($i = 0; $i < McpBrief::MAX_BLOCKS + 3; $i++) {
            $blocks[] = $this->block("Block $i", "block$i", ['Default']);
        }

        $text = McpBrief::text($blocks, 0, false);

        $this->assertSame(McpBrief::MAX_BLOCKS, substr_count($text, ': entry type `'));
        $this->assertStringContainsString('- and 3 more.', $text);
    }

    public function testGroupsAreNamedOnlyWhenThereIsMoreThanOne(): void
    {
        $blocks = [$this->block('Hero', 'hero', ['Light']), ['group' => 'Lists'] + $this->block('FAQ', 'faq', ['Default'])];

        $text = McpBrief::text($blocks, 0, false);

        $this->assertStringContainsString('- Hero (Heroes): entry type `hero`', $text);
        $this->assertStringContainsString('- FAQ (Lists): entry type `faq`', $text);
    }

    public function testNoReadyBlocksSaysSo(): void
    {
        $this->assertStringContainsString('No block is marked as ready for editors yet.', McpBrief::text([], 1, false));
    }

    public function testFindingsPutWhatBreaksABlockFirst(): void
    {
        $errors = [
            'hero' => [
                new ScanError(ScanError::CONTRACT_UNUSED_FIELD, 'The entry type has a “kicker” field…', null, 'hero'),
                new ScanError(ScanError::CONTRACT_UNFILLABLE_ARG, 'No field for “image”.', null, 'hero', 'dark'),
            ],
            'cta' => [new ScanError(ScanError::CONTRACT_UNKNOWN_FIELD, 'No “buttons” field.', null, 'cta')],
        ];
        $names = ['hero' => ['title' => 'Hero', 'entryType' => 'hero'], 'cta' => ['title' => 'CTA', 'entryType' => 'cta']];

        $rows = ContractFindings::rows($errors, $names);

        $this->assertSame(
            [['hero', 'error', 'dark'], ['cta', 'error', null], ['hero', 'notice', null]],
            array_map(static fn(array $r): array => [$r['component'], $r['severity'], $r['story']], $rows),
        );
        $this->assertSame('Hero', $rows[0]['title']);
    }
}

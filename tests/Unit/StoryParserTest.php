<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\models\ScanError;
use b10k\componentguide\services\StoryParser;
use PHPUnit\Framework\TestCase;

class StoryParserTest extends TestCase
{
    private StoryParser $parser;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->parser = new StoryParser();
        $this->fixtures = dirname(__DIR__) . '/fixtures/stories';
    }

    private function parse(string $file): array
    {
        return $this->parser->parse($this->fixtures . '/' . $file, $file);
    }

    public function testSimpleFormat(): void
    {
        $result = $this->parse('simple.php');

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['stories']);
        $this->assertSame('Primary', $result['stories'][0]->name);
        $this->assertSame(['label' => 'Save', 'variant' => 'primary'], $result['stories'][0]->args);
        $this->assertSame([], $result['meta']);
    }

    public function testRichFormat(): void
    {
        $result = $this->parse('rich.php');

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['stories']);

        $story = $result['stories'][0];
        $this->assertSame(['label' => 'Save'], $story->args);
        $this->assertSame('The default CTA.', $story->description);
        $this->assertSame('#f5f5f5', $story->background);
        // The fixture says “mobile”; the parser normalizes it to the device the
        // preview actually has (see testViewportNormalizesAliasesAndReportsTypos).
        $this->assertSame('phone', $story->viewport);
        $this->assertSame(['action', 'form'], $story->tags);
    }

    public function testMetadataNormalization(): void
    {
        $meta = $this->parse('rich.php')['meta'];

        // Whitespace trimmed, all fields present.
        $this->assertSame('Button', $meta['title']);
        $this->assertSame('Atoms', $meta['group']);
        $this->assertSame('stable', $meta['status']);
    }

    public function testInvalidReturnType(): void
    {
        $result = $this->parse('not-array.php');

        $this->assertSame([], $result['stories']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(ScanError::STORY_FILE_NOT_ARRAY, $result['errors'][0]->type);
    }

    public function testEmptyStories(): void
    {
        $result = $this->parse('empty.php');

        $this->assertSame([], $result['stories']);
        $this->assertSame(ScanError::EMPTY_STORY_FILE, $result['errors'][0]->type);
    }

    public function testInvalidArgsSkippedButOthersSurvive(): void
    {
        $result = $this->parse('invalid-args.php');

        $this->assertCount(1, $result['stories']);
        $this->assertSame('Good', $result['stories'][0]->name);

        $types = array_map(static fn(ScanError $e) => $e->type, $result['errors']);
        $this->assertContains(ScanError::INVALID_STORY_FORMAT, $types);
    }

    public function testDeterministicStoryIds(): void
    {
        $this->assertSame('primary', $this->parser->slug('Primary'));
        $this->assertSame('with-image', $this->parser->slug('With image'));
        $this->assertSame('save-cancel', $this->parser->slug('  Save / Cancel  '));
        // Stable across calls.
        $this->assertSame($this->parser->slug('A B C'), $this->parser->slug('a b c'));
    }

    public function testViewportNormalizesAliasesAndReportsTypos(): void
    {
        // Canonical, case-insensitive.
        $ok = $this->parser->parseData([
            'stories' => ['Primary' => ['args' => [], 'viewport' => 'Phone']],
        ], 'x.stories.php');
        $this->assertSame([], $ok['errors']);
        $this->assertSame('phone', $ok['stories'][0]->viewport);

        // “mobile” is the obvious way to say “phone” — accept it as an alias.
        $alias = $this->parser->parseData([
            'stories' => ['Primary' => ['args' => [], 'viewport' => 'mobile']],
        ], 'x.stories.php');
        $this->assertSame([], $alias['errors']);
        $this->assertSame('phone', $alias['stories'][0]->viewport);

        // Anything else is a typo the preview would silently ignore — say so.
        $typo = $this->parser->parseData([
            'stories' => ['Primary' => ['args' => [], 'viewport' => 'watch']],
        ], 'x.stories.php');
        $this->assertCount(1, $typo['errors']);
        $this->assertSame(ScanError::UNKNOWN_VIEWPORT, $typo['errors'][0]->type);
        $this->assertNull($typo['stories'][0]->viewport, 'the story still loads');
    }

    public function testStatusNormalizesCaseAndAliases(): void
    {
        // Canonical status, case-insensitive.
        $result = $this->parser->parseData([
            'meta' => ['status' => 'Draft'],
            'stories' => ['Primary' => ['args' => []]],
        ], 'x.stories.php');

        $this->assertSame([], $result['errors']);
        $this->assertSame('draft', $result['meta']['status']);

        // Legacy spelling keeps working: story files written before the
        // rename must not start erroring.
        $legacy = $this->parser->parseData([
            'meta' => ['status' => 'WIP'],
            'stories' => ['Primary' => ['args' => []]],
        ], 'x.stories.php');

        $this->assertSame([], $legacy['errors']);
        $this->assertSame('draft', $legacy['meta']['status']);
    }

    public function testUnknownStatusIsDroppedWithError(): void
    {
        $result = $this->parser->parseData([
            'meta' => ['status' => 'stabel'],
            'stories' => ['Primary' => ['args' => []]],
        ], 'x.stories.php');

        $this->assertArrayNotHasKey('status', $result['meta']);
        $types = array_map(static fn(ScanError $e) => $e->type, $result['errors']);
        $this->assertContains(ScanError::UNKNOWN_STATUS, $types);
        // Stories still parse — a bad status must not kill the component.
        $this->assertCount(1, $result['stories']);
    }
}

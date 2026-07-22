<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use b10k\componentguide\services\ComponentScanner;
use b10k\componentguide\services\StoryParser;
use PHPUnit\Framework\TestCase;

class ComponentScannerTest extends TestCase
{
    private ComponentScanner $scanner;
    private string $root;

    protected function setUp(): void
    {
        $this->scanner = new ComponentScanner(new StoryParser());
        $this->root = dirname(__DIR__) . '/fixtures/templates';
    }

    /**
     * @return array{components: ComponentDefinition[], errors: ScanError[]}
     */
    private function scan(string $path = '_components'): array
    {
        return $this->scanner->scan($this->root, $path, '.stories.php');
    }

    /**
     * @param ComponentDefinition[] $components
     */
    private function ids(array $components): array
    {
        return array_map(static fn(ComponentDefinition $c) => $c->id, $components);
    }

    public function testDiscoversBothConventions(): void
    {
        $ids = $this->ids($this->scan()['components']);

        // Nested (button/button.twig) and adjacent (alert.twig) both found.
        // A repeated folder/name segment collapses: button/button → "button".
        $this->assertContains('button', $ids);
        $this->assertContains('alert', $ids);
        // Deeply nested, collapsed: navigation/menu/menu → "navigation-menu".
        $this->assertContains('navigation-menu', $ids);
    }

    public function testHiddenAndUndocumentedIgnored(): void
    {
        $ids = $this->ids($this->scan()['components']);

        // widget.twig has no story → not a component.
        $this->assertNotContains('widget', $ids);
        // Story inside a dot-directory → ignored.
        $this->assertNotContains('hidden', $ids);
    }

    public function testMissingTemplateIsRecordedButNonFatal(): void
    {
        $components = $this->scan()['components'];
        $byId = [];
        foreach ($components as $c) {
            $byId[$c->id] = $c;
        }

        $this->assertArrayHasKey('no-template', $byId);
        $this->assertTrue($byId['no-template']->hasErrors());

        $types = array_map(static fn(ScanError $e) => $e->type, $byId['no-template']->errors);
        $this->assertContains(ScanError::MISSING_TEMPLATE, $types);

        // A broken component must not stop valid ones from loading.
        $this->assertArrayHasKey('button', $byId);
        $this->assertFalse($byId['button']->hasErrors());
    }

    public function testMetaOverridesTitleAndGroup(): void
    {
        $byId = [];
        foreach ($this->scan()['components'] as $c) {
            $byId[$c->id] = $c;
        }

        $this->assertSame('Button', $byId['button']->title);
        $this->assertSame('Atoms', $byId['button']->effectiveGroup());
        // No meta group → falls back to the containing folder.
        $this->assertSame('Menu', $byId['navigation-menu']->title);
        $this->assertSame('navigation', $byId['navigation-menu']->effectiveGroup());
    }

    public function testMissingDirectory(): void
    {
        $result = $this->scan('_does_not_exist');

        $this->assertSame([], $result['components']);
        $this->assertSame(ScanError::MISSING_COMPONENT_DIRECTORY, $result['errors'][0]->type);
    }

    public function testTraversalIsBlocked(): void
    {
        // ".." resolves outside the templates root → rejected.
        $result = $this->scan('..');

        $this->assertSame([], $result['components']);
        $this->assertNotEmpty($result['errors']);
        $this->assertContains($result['errors'][0]->type, [
            ScanError::INVALID_COMPONENT_DIRECTORY,
            ScanError::MISSING_COMPONENT_DIRECTORY,
        ]);
    }

    public function testDuplicateIdsReported(): void
    {
        $result = $this->scan();
        $ids = $this->ids($result['components']);

        // foo/bar and foo-bar both slug to "foo-bar" → exactly one survives.
        $this->assertSame(1, count(array_filter($ids, static fn($id) => $id === 'foo-bar')));

        $types = array_map(static fn(ScanError $e) => $e->type, $result['errors']);
        $this->assertContains(ScanError::DUPLICATE_COMPONENT_ID, $types);
    }

    public function testConsistentSorting(): void
    {
        // Deterministic: components come back grouped, and re-sorting by the same
        // (group, title) key leaves the order unchanged.
        $components = $this->scan()['components'];

        $resorted = $components;
        usort($resorted, static function (ComponentDefinition $a, ComponentDefinition $b): int {
            return [strtolower($a->effectiveGroup()), strtolower($a->title)]
                <=> [strtolower($b->effectiveGroup()), strtolower($b->title)];
        });

        $this->assertSame($this->ids($components), $this->ids($resorted));

        // Two scans of the same tree yield identical ordering.
        $this->assertSame($this->ids($components), $this->ids($this->scan()['components']));
    }
}

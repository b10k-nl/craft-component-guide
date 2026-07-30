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

    /** @var string[] Temp trees created by makeTemplatesRoot(), removed in tearDown(). */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        $this->scanner = new ComponentScanner(new StoryParser());
        $this->root = dirname(__DIR__) . '/fixtures/templates';
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            $this->removeTree($dir);
        }
        $this->tmpDirs = [];
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

    public function testMarkerFileDiscoversUndocumentedComponents(): void
    {
        $root = $this->makeTemplatesRoot([
            '_components/BLOCKS.md' => "# Blocks\n\nReusable page blocks.\n\n## For developers\n\nInternal notes.\n",
            '_components/card.twig' => '<div></div>',
            '_components/_partial.twig' => '<div></div>',
            '_components/hero/hero.twig' => '<div></div>',
            '_components/banner.twig' => '<div></div>',
            '_components/banner.stories.php' => "<?php return ['Default' => []];",
        ]);

        $result = $this->scanner->scan($root, '_components', '.stories.php');
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c->id] = $c;
        }

        // Undocumented twig files under the marker are discovered…
        $this->assertArrayHasKey('card', $byId);
        $this->assertFalse($byId['card']->isDocumented);
        $this->assertSame([], $byId['card']->stories);
        // …including the nested convention, with the same folder/name collapse.
        $this->assertArrayHasKey('hero', $byId);
        $this->assertFalse($byId['hero']->isDocumented);
        // Documented components stay documented and are not duplicated.
        $this->assertTrue($byId['banner']->isDocumented);
        // Underscore-prefixed files are internal partials.
        $this->assertArrayNotHasKey('partial', $byId);

        // Components without an explicit meta group inherit the marker's H1 —
        // documented and undocumented alike, so the section stays unified.
        $this->assertSame('Blocks', $byId['card']->effectiveGroup());
        $this->assertSame('Blocks', $byId['hero']->effectiveGroup());
        $this->assertSame('Blocks', $byId['banner']->effectiveGroup());

        // Marker metadata: H1 → label, intro text → description — and only
        // the intro, nothing below the next heading.
        $this->assertSame('Blocks', $result['groupMeta']['']['label']);
        $this->assertSame('Reusable page blocks.', $result['groupMeta']['']['description']);
    }

    public function testWithoutMarkerBehaviourIsUnchanged(): void
    {
        $root = $this->makeTemplatesRoot([
            '_components/card.twig' => '<div></div>',
            '_components/banner.twig' => '<div></div>',
            '_components/banner.stories.php' => "<?php return ['Default' => []];",
        ]);

        $result = $this->scanner->scan($root, '_components', '.stories.php');

        $this->assertSame(['banner'], $this->ids($result['components']));
        $this->assertSame([], $result['groupMeta']);
    }

    public function testMarkerOnlyCoversItsSubtree(): void
    {
        $root = $this->makeTemplatesRoot([
            '_components/outside.twig' => '<div></div>',
            '_components/cards/GUIDE.md' => "# Cards\n",
            '_components/cards/activity.twig' => '<div></div>',
        ]);

        $result = $this->scanner->scan($root, '_components', '.stories.php');
        $ids = $this->ids($result['components']);

        $this->assertContains('cards-activity', $ids);
        $this->assertNotContains('outside', $ids);
        // The marker's own folder is the group key…
        $this->assertSame('Cards', $result['groupMeta']['cards']['label']);
        // …and its H1 becomes the group of the components it covers.
        foreach ($result['components'] as $c) {
            if ($c->id === 'cards-activity') {
                $this->assertSame('Cards', $c->effectiveGroup());
            }
        }
    }

    public function testMarkerPrecedenceAndDuplicateWarning(): void
    {
        $root = $this->makeTemplatesRoot([
            '_components/GUIDE.md' => "# From Guide\n",
            '_components/BLOCKS.md' => "# From Blocks\n",
            '_components/card.twig' => '<div></div>',
        ]);

        $result = $this->scanner->scan($root, '_components', '.stories.php');

        // GUIDE.md wins; the duplicate is a non-fatal warning.
        $this->assertSame('From Guide', $result['groupMeta']['']['label']);
        $types = array_map(static fn(ScanError $e) => $e->type, $result['errors']);
        $this->assertContains(ScanError::DUPLICATE_MARKER, $types);
        // The scan itself still succeeds.
        $this->assertContains('card', $this->ids($result['components']));
    }

    public function testFingerprintTracksMarkersAndCoveredTemplates(): void
    {
        $root = $this->makeTemplatesRoot([
            '_components/BLOCKS.md' => "# Blocks\n",
            '_components/card.twig' => '<div></div>',
        ]);

        $a = $this->scanner->fingerprint($root, '_components', '.stories.php');

        // Adding an undocumented template under a marker changes the fingerprint.
        file_put_contents($root . '/_components/extra.twig', '<div></div>');
        $b = $this->scanner->fingerprint($root, '_components', '.stories.php');
        $this->assertNotSame($a, $b);

        // Removing the marker changes it again (undocumented mode off).
        unlink($root . '/_components/BLOCKS.md');
        $c = $this->scanner->fingerprint($root, '_components', '.stories.php');
        $this->assertNotSame($b, $c);
    }

    public function testMarkerGroupsMirrorFolderHierarchy(): void
    {
        $root = $this->makeTemplatesRoot([
            '_components/COMPONENTS.md' => "# Components\n",
            '_components/toast.twig' => '<div></div>',
            '_components/cards/article.twig' => '<div></div>',
            '_components/popups/contact.twig' => '<div></div>',
            '_components/popups/GUIDE.md' => "# Modals\n\nOverlay dialogs.\n",
        ]);

        $result = $this->scanner->scan($root, '_components', '.stories.php');
        $byId = [];
        foreach ($result['components'] as $c) {
            $byId[$c->id] = $c;
        }

        // The root marker's H1 names the branch; plain subfolders extend it.
        $this->assertSame('Components', $byId['toast']->effectiveGroup());
        $this->assertSame('Components / Cards', $byId['cards-article']->effectiveGroup());
        // A nested marker's H1 replaces its own folder's name in the chain…
        $this->assertSame('Components / Modals', $byId['popups-contact']->effectiveGroup());
        // …and its metadata is keyed to the composed group name.
        $this->assertSame('Components / Modals', $result['groupMeta']['popups']['group']);
        $this->assertSame('Overlay dialogs.', $result['groupMeta']['popups']['description']);
    }

    public function testDispatcherAndFallbackTemplatesAreNotComponents(): void
    {
        // The dispatcher pattern from the README puts index.twig (entry point)
        // and undefined.twig (fallback) next to the real blocks; neither is a
        // component.
        $root = $this->makeTemplatesRoot([
            '_pagebuilder/GUIDE.md' => "# Blocks\n",
            '_pagebuilder/index.twig' => '<div></div>',
            '_pagebuilder/undefined.twig' => '<div></div>',
            '_pagebuilder/feature-bar.twig' => '<div></div>',
        ]);

        $result = $this->scanner->scan($root, '', '.stories.php');

        $this->assertSame(['pagebuilder-feature-bar'], $this->ids($result['components']));
        // Kebab-case filenames get humanized titles.
        $this->assertSame('Feature Bar', $result['components'][0]->title);
    }

    /**
     * Builds a throwaway templates tree in the system temp dir.
     *
     * @param array<string, string> $files Relative path => file contents.
     * @return string Absolute path of the templates root.
     */
    private function makeTemplatesRoot(array $files): string
    {
        $root = sys_get_temp_dir() . '/cg-scanner-test-' . bin2hex(random_bytes(6));

        foreach ($files as $relativePath => $contents) {
            $path = $root . '/' . $relativePath;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }
            file_put_contents($path, $contents);
        }

        $this->tmpDirs[] = $root;
        return $root;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

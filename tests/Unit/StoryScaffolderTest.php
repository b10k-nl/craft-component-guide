<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\services\StoryParser;
use b10k\componentguide\services\StoryScaffolder;
use PHPUnit\Framework\TestCase;

class StoryScaffolderTest extends TestCase
{
    private StoryScaffolder $scaffolder;

    /** @var string[] */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        $this->scaffolder = new StoryScaffolder();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
        $this->tmpDirs = [];
    }

    public function testAnalyzeExtractsRootVariablesAndSkipsLocals(): void
    {
        $args = $this->scaffolder->analyze(<<<'TWIG'
            {# heading is the visible title #}
            {% set classes = 'card' %}
            <div class="{{ classes }}">
                <h2>{{ heading }}</h2>
                {{ bodyHtml|raw }}
                {% if showBadge %}<span>{{ badgeLabel|default('New') }}</span>{% endif %}
                <a href="{{ viewAllUrl }}">{{ dump(craft.app) }}</a>
            </div>
            TWIG);

        // Locals, globals, filters and function names are not args.
        $this->assertArrayNotHasKey('classes', $args);
        $this->assertArrayNotHasKey('craft', $args);
        $this->assertArrayNotHasKey('raw', $args);
        $this->assertArrayNotHasKey('dump', $args);

        // Name-based guesses, emitted as placeholder tokens.
        $this->assertSame('@lorem_w_4', $args['heading']);
        $this->assertSame('@lorem_p_1', $args['bodyHtml']);
        $this->assertTrue($args['showBadge']);
        $this->assertSame('#', $args['viewAllUrl']);
        // |default() literal wins over the guess.
        $this->assertSame('New', $args['badgeLabel']);
    }

    public function testAnalyzeBuildsSampleItemsFromLoopUsage(): void
    {
        $args = $this->scaffolder->analyze(<<<'TWIG'
            {% for item in items %}
                <img src="{{ item.iconUrl }}" alt="{{ item.iconAlt }}">
                <h3>{{ item.title }}</h3>
            {% endfor %}
            TWIG);

        $this->assertArrayHasKey('items', $args);
        $this->assertArrayNotHasKey('item', $args);
        $this->assertCount(3, $args['items']);
        $first = $args['items'][0];
        $this->assertSame(['iconUrl', 'iconAlt', 'title'], array_keys($first));
        $this->assertSame('@icon', $first['iconUrl']);
        // Identical tokens are fine: the resolver seeds them per array index,
        // so the three items still render as three different samples.
        $this->assertSame('@lorem_w_4', $first['title']);
        $this->assertSame('@lorem_w_4', $args['items'][1]['title']);
    }

    public function testAnalyzeHandlesSelfDefaultingSetIdiom(): void
    {
        // `{% set x = x ?? … %}` is the most common way to give a component
        // prop a fallback — x must stay an incoming arg, not become a local.
        $args = $this->scaffolder->analyze(<<<'TWIG'
            {% set items = items ?? [] %}
            {% set heading = heading ?? 'Our stats' %}
            {% set gutter = 'mx-5' %}
            {% if items|length %}
                <h2 class="{{ gutter }}">{{ heading }}</h2>
                {% for it in items %}
                    <img src="{{ it.iconUrl }}" alt="{{ it.iconAlt }}">
                    <span>{{ it.value }}</span><span>{{ it.label }}</span>
                {% endfor %}
            {% endif %}
            TWIG);

        $this->assertSame(['items', 'heading'], array_keys($args));
        $this->assertCount(3, $args['items']);
        $this->assertSame(['iconUrl', 'iconAlt', 'value', 'label'], array_keys($args['items'][0]));
        // The ?? literal wins over the name-based guess.
        $this->assertSame('Our stats', $args['heading']);
        // A genuinely local set stays out of the args.
        $this->assertArrayNotHasKey('gutter', $args);
    }

    public function testAccumulatingSetStaysLocal(): void
    {
        $args = $this->scaffolder->analyze(<<<'TWIG'
            {% set classes = 'card' %}
            {% set classes = classes ~ ' ' ~ variant %}
            <div class="{{ classes }}">{{ heading }}</div>
            TWIG);

        $this->assertArrayNotHasKey('classes', $args);
        $this->assertArrayHasKey('variant', $args);
        $this->assertArrayHasKey('heading', $args);
    }

    public function testQuotedTextIsNotMistakenForVariables(): void
    {
        // Tailwind classes inside a ternary used to leak in as “lg” and “mt”.
        $args = $this->scaffolder->analyze(<<<'TWIG'
            <section class="mx-5 lg:mx-[60px]">
                {% if heading ?? null %}<h2>{{ heading }}</h2>{% endif %}
                <div class="max-w-[1080px] {{ heading ?? null ? 'mt-4 lg:mt-10' }} mv-richtext">
                    {{ bodyHtml|raw }}
                </div>
                {% include '_v2/_components/divider.twig' %}
            </section>
            TWIG);

        $this->assertSame(['heading', 'bodyHtml'], array_keys($args));
    }

    public function testDottedAccessBecomesAStandInHash(): void
    {
        // The “anti-adapter”: templates written against a Matrix block read the
        // same values off a plain hash, so a story can stand in for the entry.
        $args = $this->scaffolder->analyze(<<<'TWIG'
            {% set eyebrow = block.eyebrow ?? null %}
            {% set heading = block.heading ?? null %}
            {% if eyebrow or heading %}
                <span>{{ eyebrow }}</span>
                <h2>{{ heading }}</h2>
                <img src="{{ block.image.url }}" alt="{{ block.image.alt }}">
            {% endif %}
            TWIG);

        $this->assertSame(['block'], array_keys($args));
        // Key order depends on expression traversal — assert the set, not it.
        $this->assertEqualsCanonicalizing(['eyebrow', 'heading', 'image'], array_keys($args['block']));
        // Nested paths nest.
        $this->assertEqualsCanonicalizing(['url', 'alt'], array_keys($args['block']['image']));
        $this->assertSame('@image', $args['block']['image']['url']);
        // Locals derived from the block stay out of the args.
        $this->assertArrayNotHasKey('eyebrow', $args);
    }

    public function testBlockTagIsNotMistakenForTheBlockVariable(): void
    {
        $args = $this->scaffolder->analyze(<<<'TWIG'
            {% extends '_layout.twig' %}
            {% block content %}
                <h2>{{ block.heading }}</h2>
            {% endblock %}
            TWIG);

        // `{% block content %}` declares a name …
        $this->assertArrayNotHasKey('content', $args);
        // … while `block.heading` is a real prop.
        $this->assertSame(['heading'], array_keys($args['block']));
    }

    public function testMethodCallsAreDeclaredButFlagged(): void
    {
        $source = <<<'TWIG'
            {% set urls = layout.urls.all() ?? [] %}
            {% for url in urls %}<a href="{{ url.href }}">{{ url.label }}</a>{% endfor %}
            TWIG;

        $args = $this->scaffolder->analyze($source);

        // The method itself can't be faked, but the prefix is declared …
        $this->assertSame(['urls'], array_keys($args['layout']));
        // … and the scaffold warns that the preview will be incomplete.
        $this->assertTrue($this->scaffolder->needsRuntimeData($source));
        $this->assertFalse($this->scaffolder->needsRuntimeData('<h2>{{ block.heading }}</h2>'));
    }

    public function testDetectsStatesFromLiteralComparisons(): void
    {
        $source = <<<'TWIG'
            {% set theme = theme ?? 'light' %}
            <section class="{{ theme == 'dark' ? 'bg-slate-900' : 'bg-orange-50' }}">
                <h2>{{ heading }}</h2>
            </section>
            TWIG;

        $state = $this->scaffolder->detectStates($source);

        $this->assertSame('theme', $state['var']);
        // Both the comparison and the ?? fallback count as states.
        $this->assertEqualsCanonicalizing(['dark', 'light'], $state['values']);

        // A template with no switch has no states to split.
        $this->assertNull($this->scaffolder->detectStates('<h2>{{ heading }}</h2>'));
    }

    public function testScaffoldWritesOneStoryPerStateOnRequest(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/hero.twig', <<<'TWIG'
            {% set mediaPosition = mediaPosition ?? 'background' %}
            <section>
                {% if mediaPosition == 'right' %}<img src="{{ imageUrl }}">{% endif %}
                <h2>{{ heading }}</h2>
            </section>
            TWIG);

        $component = $this->undocumented($dir . '/hero.twig', 'Hero');
        $path = $this->scaffolder->scaffold($component, '.stories.php', true);

        $result = (new StoryParser())->parse($path, 'hero.stories.php');
        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['stories']);

        // Names read like the states they document — the variable's first word
        // plus the value, since “Right” alone would say nothing.
        $names = array_map(static fn($s) => $s->name, $result['stories']);
        $this->assertEqualsCanonicalizing(['Media background', 'Media right'], $names);

        // Each story differs only in the switched arg.
        foreach ($result['stories'] as $story) {
            $expected = str_contains($story->name, 'right') ? 'right' : 'background';
            $this->assertSame($expected, $story->args['mediaPosition']);
            $this->assertSame('@lorem_w_4', $story->args['heading']);
        }
    }

    public function testScaffoldStaysSingleWithoutTheOptIn(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/hero.twig', "{% set theme = theme ?? 'light' %}<b class=\"{{ theme == 'dark' ? 'x' : 'y' }}\">{{ heading }}</b>");

        $path = $this->scaffolder->scaffold($this->undocumented($dir . '/hero.twig', 'Hero'), '.stories.php');
        $result = (new StoryParser())->parse($path, 'hero.stories.php');

        $this->assertCount(1, $result['stories']);
        $this->assertSame('Default', $result['stories'][0]->name);
    }

    public function testRenderedScaffoldIsAValidRichStoryFile(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/card.twig', '<h2>{{ heading }}</h2>{{ bodyHtml|raw }}');

        $component = $this->undocumented($dir . '/card.twig', 'Card');
        $path = $this->scaffolder->scaffold($component, '.stories.php');

        $this->assertFileExists($path);
        $this->assertSame($dir . '/card.stories.php', $path);

        // The generated file must round-trip through the real parser cleanly.
        $result = (new StoryParser())->parse($path, 'card.stories.php');
        $this->assertSame([], $result['errors']);
        $this->assertSame('Card', $result['meta']['title']);
        $this->assertSame('draft', $result['meta']['status']);
        $this->assertCount(1, $result['stories']);
        $this->assertSame('@lorem_w_4', $result['stories'][0]->args['heading']);
    }

    public function testTwigScaffoldIsWrittenAsATwigStoryTemplate(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/card.twig', "{# A card. #}\n<h2>{{ heading }}</h2>");

        $path = $this->scaffolder->scaffold($this->undocumented($dir . '/card.twig', "Bob's Card"), '.stories.twig');
        $this->assertSame($dir . '/card.stories.twig', $path);

        $source = file_get_contents($path);
        // Pure-data Twig story shape (see TwigStoryLoader).
        $this->assertStringContainsString('{% set meta = {', $source);
        $this->assertStringContainsString('{% set stories = {', $source);
        $this->assertStringContainsString("'Default': {", $source);
        $this->assertStringContainsString('args: {', $source);
        $this->assertStringContainsString("heading: '@lorem_w_4',", $source);
        // Quotes in values are escaped, not left to break the template.
        $this->assertStringContainsString("title: 'Bob\\'s Card',", $source);
    }

    public function testScaffoldNamesTheGroupKeyWithoutSettingIt(): void
    {
        // `group` is the one meta key the scaffolder can't guess, and the one a
        // developer would otherwise never learn exists — it is written out empty
        // so the option sits where you would type it. Empty must stay
        // equivalent to absent: the parser trims and drops it, and the component
        // keeps inheriting its group from the folder or marker file above it.
        // Filling it in here would freeze that inheritance for every scaffold.
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/card.twig', '<h2>{{ heading }}</h2>');

        $php = $this->scaffolder->scaffold($this->undocumented($dir . '/card.twig', 'Card'), '.stories.php');
        $this->assertStringContainsString('group', file_get_contents($php));

        $meta = (new StoryParser())->parse($php, 'card.stories.php')['meta'];
        $this->assertSame('Card', $meta['title']);
        $this->assertArrayNotHasKey('group', $meta);
    }

    public function testTwigScaffoldNamesTheGroupKeyWithoutSettingIt(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/panel.twig', '<h2>{{ heading }}</h2>');

        $path = $this->scaffolder->scaffold($this->undocumented($dir . '/panel.twig', 'Panel'), '.stories.twig');

        $this->assertStringContainsString("group: '',", file_get_contents($path));
    }

    public function testDescriptionComesFromTheLeadingComment(): void
    {
        // First sentence only — the rest is developer notes.
        $this->assertSame(
            'Stats Bar page-builder block (presentational partial).',
            $this->scaffolder->describe(<<<'TWIG'
                {# Stats Bar page-builder block (presentational partial).
                   Figma: desktop/tablet (node 1232:272) = one light bar, 4-col row.

                   Params:
                     items  list of { iconUrl, iconAlt, value, label } #}
                <section></section>
                TWIG),
        );

        // No leading comment → no description.
        $this->assertNull($this->scaffolder->describe('<h2>{{ heading }}</h2>'));
        // A comment further down the file does not describe the component.
        $this->assertNull($this->scaffolder->describe("<div>\n{# inline note. #}\n</div>"));
    }

    public function testScaffoldRefusesToOverwrite(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/card.twig', '{{ heading }}');
        file_put_contents($dir . '/card.stories.php', "<?php return ['Default' => []];");

        $this->expectException(\RuntimeException::class);
        $this->scaffolder->scaffold($this->undocumented($dir . '/card.twig', 'Card'), '.stories.php');
    }

    public function testScaffoldRefusesDocumentedComponents(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir . '/card.twig', '{{ heading }}');

        $component = $this->undocumented($dir . '/card.twig', 'Card');
        $component->isDocumented = true;

        $this->expectException(\RuntimeException::class);
        $this->scaffolder->scaffold($component, '.stories.php');
    }

    private function undocumented(string $templateAbs, string $title): ComponentDefinition
    {
        return new ComponentDefinition(
            id: strtolower($title),
            name: strtolower($title),
            title: $title,
            templatePath: basename($templateAbs),
            absoluteTemplatePath: $templateAbs,
            isDocumented: false,
        );
    }

    private function makeTmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/cg-scaffolder-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        $this->tmpDirs[] = $dir;
        return $dir;
    }
}

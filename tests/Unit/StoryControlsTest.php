<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\StoryControls;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\services\StoryScaffolder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The controls on a component page, and the way changed values come back to
 * the preview. What a control looks like decides whether a client reviewing a
 * block on staging can try it at all: a theme typed by hand is a theme spelt
 * wrong.
 */
class StoryControlsTest extends TestCase
{
    private function story(string $id, array $args): StoryDefinition
    {
        return new StoryDefinition($id, ucfirst($id), ucfirst($id), $args);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $controls): array
    {
        $out = [];
        foreach ($controls as $control) {
            $out[$control['name']] = $control;
        }

        return $out;
    }

    public function testKindsFollowTheValuesTheStoriesGive(): void
    {
        $default = $this->story('default', [
            'heading' => 'Ride better',
            'body' => "Two lines\nof text",
            'featured' => true,
            'columns' => 3,
            'items' => [['title' => 'A'], ['title' => 'B']],
            'subtitle' => null,
        ]);
        $other = $this->story('other', ['heading' => 'Book a fit', 'columns' => 2, 'extra' => '<p>Rich</p>']);

        $controls = $this->byName(StoryControls::build($default, [$default, $other]));

        $this->assertSame('text', $controls['heading']['kind']);
        $this->assertSame('textarea', $controls['body']['kind']);
        $this->assertSame('bool', $controls['featured']['kind']);
        $this->assertSame('number', $controls['columns']['kind']);
        $this->assertSame('json', $controls['items']['kind']);
        $this->assertSame('text', $controls['subtitle']['kind'], 'null alone says nothing — text is the safe guess');
        $this->assertSame('textarea', $controls['extra']['kind'], 'markup gets room');
    }

    public function testArgumentsOfOtherStoriesAreOfferedButNotSet(): void
    {
        $default = $this->story('default', ['heading' => 'Ride better']);
        $withButton = $this->story('button', ['heading' => 'Book', 'buttonLabel' => 'Book now']);

        $controls = StoryControls::build($default, [$default, $withButton]);

        $this->assertSame(['heading', 'buttonLabel'], array_column($controls, 'name'), 'this story first, then the rest');
        $this->assertTrue($controls[0]['set']);
        $this->assertFalse($controls[1]['set']);
        $this->assertNull($controls[1]['value']);
    }

    public function testTextSuggestsWhatTheOtherStoriesSay(): void
    {
        $a = $this->story('a', ['heading' => 'One']);
        $b = $this->story('b', ['heading' => 'Two']);
        $c = $this->story('c', ['heading' => str_repeat('x', 200)]);

        $heading = StoryControls::build($a, [$a, $b, $c])[0];

        $this->assertSame(['Two'], $heading['suggestions'], 'not its own value, nothing too long to be a choice');
    }

    public function testATemplateComparisonMakesASelect(): void
    {
        $source = <<<'TWIG'
            {% set theme = theme ?? 'light' %}
            <section class="{{ theme == 'dark' ? 'a' : 'b' }} {{ size == 'sm' ? 's' : '' }}{{ size == 'lg' ? 'l' : '' }}">
            TWIG;
        $enums = (new StoryScaffolder())->detectEnums($source);
        $this->assertEqualsCanonicalizing(['dark', 'light'], $enums['theme']);
        $this->assertSame(['sm', 'lg'], $enums['size']);

        $story = $this->story('default', ['theme' => 'dark', 'heading' => 'Hi', 'size' => 'xl']);
        $controls = $this->byName(StoryControls::build($story, [$story], $enums));

        $this->assertSame('select', $controls['theme']['kind']);
        $this->assertSame('text', $controls['heading']['kind']);
        $this->assertSame(['sm' => 'sm', 'lg' => 'lg', 'xl' => 'xl'], $controls['size']['options'], 'the value it has stays choosable');
    }

    public function testDetectStatesStillPicksTheBiggestSwitch(): void
    {
        $source = "{{ a == 'x' }}{{ a == 'y' }}{{ b == '1' }}{{ b == '2' }}{{ b == '3' }}";

        $this->assertSame(['var' => 'b', 'values' => ['1', '2', '3']], (new StoryScaffolder())->detectStates($source));
        $this->assertSame([], (new StoryScaffolder())->detectEnums('<h2>{{ heading }}</h2>'));
    }

    public function testABlocksFieldsComeFirstAndDecideTheKind(): void
    {
        $fields = [
            'heading' => ['kind' => 'text', 'name' => 'Heading'],
            'bodyText' => ['kind' => 'html', 'name' => 'Body'],
            'theme' => ['kind' => 'option', 'name' => 'Theme', 'options' => ['light' => 'Light', 'dark' => 'Dark']],
            'reversed' => ['kind' => 'bool', 'name' => 'Reversed'],
            'heroImage' => ['kind' => 'assets', 'name' => 'Image'],
            'gridCards' => ['kind' => 'matrix', 'name' => 'Cards'],
        ];
        $story = $this->story('dark', ['blockIndex' => 2, 'theme' => 'dark', 'heading' => 'Ride better', 'heroImage' => 'https://example.test/a.jpg']);

        $controls = StoryControls::build($story, [$story], [], $fields, true);
        $byName = $this->byName($controls);

        $this->assertSame(
            ['heading', 'bodyText', 'theme', 'reversed', 'heroImage', 'gridCards', 'blockIndex'],
            array_column($controls, 'name'),
            'every field, in the entry type\'s order, even the ones no story fills; then the rest',
        );
        $this->assertSame('Theme', $byName['theme']['label']);
        $this->assertSame('select', $byName['theme']['kind']);
        $this->assertSame(['light' => 'Light', 'dark' => 'Dark'], $byName['theme']['options']);
        $this->assertSame('textarea', $byName['bodyText']['kind']);
        $this->assertSame('bool', $byName['reversed']['kind']);
        $this->assertFalse($byName['reversed']['set']);
        $this->assertSame('text', $byName['heroImage']['kind'], 'one URL is a text');
        $this->assertSame('json', $byName['gridCards']['kind']);
        $this->assertSame('number', $byName['blockIndex']['kind']);
        $this->assertNull($byName['blockIndex']['label']);
    }

    public function testAnAdaptersFieldsOnlyTypeTheArgumentsTheStoriesHave(): void
    {
        // Presentational args fed by an adapter: the field says what kind of
        // value it is, but does not add arguments of its own.
        $fields = ['tone' => ['kind' => 'option', 'name' => 'Theme', 'options' => ['light' => 'Light', 'dark' => 'Dark']]];
        $story = $this->story('default', ['tone' => 'light', 'title' => 'Hi']);

        $controls = StoryControls::build($story, [$story], [], $fields);

        $this->assertSame(['tone', 'title'], array_column($controls, 'name'));
        $this->assertSame('select', $controls[0]['kind']);
    }

    public function testImagesCanBeChosenFromTheSitesAssets(): void
    {
        $fields = ['heroImage' => ['kind' => 'assets', 'name' => 'Image'], 'gallery' => ['kind' => 'assets', 'name' => 'Gallery']];
        $story = $this->story('default', [
            'heroImage' => '@image_1600x600',
            'gallery' => ['https://example.test/a.jpg'],
            'photo' => 'https://cdn.example.test/team/anna.webp?w=400',
            'avatar' => '@image_200x200',
            'logo' => '/uploads/logo.svg',
            'url' => 'https://example.test/about',
            'heading' => 'Hi',
        ]);

        $controls = $this->byName(StoryControls::build($story, [$story], [], $fields, true));

        $this->assertTrue($controls['heroImage']['image'], 'an assets field holding one URL');
        $this->assertFalse($controls['gallery']['image'], 'a list is edited as JSON');
        $this->assertTrue($controls['photo']['image'], 'a URL to an image file');
        $this->assertTrue($controls['avatar']['image'], 'an image token');
        $this->assertTrue($controls['logo']['image'], 'a root-relative path, as Choose an image writes it');
        $this->assertFalse($controls['url']['image']);
        $this->assertFalse($controls['heading']['image']);
    }

    public function testOverridesAreAnObjectOfArgumentNames(): void
    {
        $this->assertSame([], StoryControls::decodeOverrides(''));
        $this->assertSame([], StoryControls::decodeOverrides('{}'));
        $this->assertSame(
            ['heading' => 'Hi', 'items' => [['title' => 'A']], 'on' => true, 'data-x' => 1],
            StoryControls::decodeOverrides('{"heading":"Hi","items":[{"title":"A"}],"on":true,"data-x":1}'),
        );

        foreach (['[1,2]', '"text"', '{bad json', '{"1":"numeric key"}', '{"has space":1}', '{"../x":1}'] as $raw) {
            try {
                StoryControls::decodeOverrides($raw);
                $this->fail('accepted ' . $raw);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testOverridesHaveLimits(): void
    {
        $deep = str_repeat('[', StoryControls::MAX_DEPTH + 2) . str_repeat(']', StoryControls::MAX_DEPTH + 2);
        $this->expectException(InvalidArgumentException::class);
        StoryControls::decodeOverrides('{"a":' . $deep . '}');
    }

    public function testOverridesHaveASizeLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StoryControls::decodeOverrides('{"a":"' . str_repeat('x', StoryControls::MAX_BYTES) . '"}');
    }

    public function testOverridesReplaceTopLevelValuesOnly(): void
    {
        $story = new StoryDefinition('s', 'S', 'S', ['heading' => 'A', 'items' => [1, 2, 3], 'keep' => true], 'Desc', '#000', 'mobile', ['t']);

        $changed = StoryControls::withOverrides($story, ['heading' => 'B', 'items' => [9], 'added' => 'x']);

        $this->assertSame(['heading' => 'B', 'items' => [9], 'keep' => true, 'added' => 'x'], $changed->args);
        $this->assertSame(['s', 'S', 'Desc', '#000', 'mobile', ['t']], [$changed->id, $changed->title, $changed->description, $changed->background, $changed->viewport, $changed->tags]);
        $this->assertSame(['heading' => 'A', 'items' => [1, 2, 3], 'keep' => true], $story->args, 'the story itself is untouched');
        $this->assertSame($story, StoryControls::withOverrides($story, []));
    }
}

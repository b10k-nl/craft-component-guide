<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\UserStoryBuilder;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\services\AdapterResolver;
use b10k\componentguide\services\ContractChecker;
use PHPUnit\Framework\TestCase;

/**
 * Templates written the usual Craft way: no adapter, the block template reads
 * the entry the dispatcher hands it. The fixtures are the three Velo Studio
 * blocks rewritten that way for the 19.10 experiment, verbatim — the first run
 * showed previews quietly missing cards, images and the dark theme.
 */
class SelfReadingTemplateTest extends TestCase
{
    private AdapterResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AdapterResolver();
    }

    private const CTA = <<<'TWIG'
        {# Full-width call to action. #}
        <section class="rounded-3xl bg-orange-600 px-10 py-12 text-center">
            <h2 class="text-3xl font-extrabold text-white tracking-tight">{{ block.heading }}</h2>
            {% if block.bodyText %}
                <div class="mt-2 text-orange-100 max-w-xl mx-auto">{{ block.bodyText }}</div>
            {% endif %}
            <a href="{{ block.buttonUrl ?? '#' }}" class="mt-6 inline-block">{{ block.buttonLabel ?? 'Join now' }}</a>
        </section>
        TWIG;

    private const CARDS = <<<'TWIG'
        <section class="px-2">
            {% if block.heading %}
                <h2>{{ block.heading }}</h2>
            {% endif %}
            <div class="grid">
                {% for card in block.gridCards.all() %}
                    <div class="card">
                        <div class="text-3xl">{{ card.cardIcon ?? '🚴' }}</div>
                        <h3>{{ card.title }}</h3>
                        <p>{{ card.cardText }}</p>
                    </div>
                {% endfor %}
            </div>
        </section>
        TWIG;

    private const HERO = <<<'TWIG'
        {% set image = block.heroImage.one() %}
        {% set dark = block.theme.value == 'dark' %}
        <section class="{{ dark ? 'bg-slate-900' : 'bg-orange-50' }}">
            {% if image %}
                <img src="{{ image.url }}" alt="">
            {% endif %}
            {% if block.eyebrow %}<p>{{ block.eyebrow }}</p>{% endif %}
            <h1>{{ block.heading }}</h1>
            {% if block.bodyText %}<div>{{ block.bodyText }}</div>{% endif %}
            {% if block.buttonLabel %}<a href="{{ block.buttonUrl ?? '#' }}">{{ block.buttonLabel }}</a>{% endif %}
        </section>
        TWIG;

    /** A presentational component: its roots are story arguments, not an entry. */
    private const PRESENTATIONAL = <<<'TWIG'
        <section>
            <h2>{{ heading }}</h2>
            {% if image %}<img src="{{ image.url }}" alt="{{ image.alt }}">{% endif %}
            {% for b in buttons %}<a href="{{ b.url }}">{{ b.label }}</a>{% endfor %}
        </section>
        TWIG;

    private const HERO_FIELDS = ['eyebrow', 'heading', 'bodyText', 'theme', 'reversed', 'heroImage', 'buttonLabel', 'buttonUrl'];

    public function testFindsTheBlockVariableAndMapsFieldsToThemselves(): void
    {
        $binding = $this->resolver->parseSelf(
            self::CTA,
            '_blocks/ctaBanner.twig',
            ['heading', 'bodyText', 'buttonLabel', 'buttonUrl'],
            ['heading', 'bodyText', 'buttonLabel', 'buttonUrl'],
        );

        $this->assertNotNull($binding);
        $this->assertTrue($binding->selfReading);
        $this->assertSame('block', $binding->blockRoot);
        $this->assertSame('_blocks/ctaBanner.twig', $binding->adapterPath);
        $this->assertEqualsCanonicalizing(['heading', 'bodyText', 'buttonUrl', 'buttonLabel'], array_keys($binding->args));
        $this->assertSame('bodyText', $binding->fieldFor('bodyText'));
        $this->assertSame('block.heading', $binding->args['heading']);
    }

    public function testStoryArgumentsWithTheSameNameDoNotHideTheEntry(): void
    {
        // Stories for a self-reading template are written in field handles —
        // `heading` is a story argument here — while the entry variable is
        // `block`, which no story sets.
        $binding = $this->resolver->parseSelf(self::CTA, 'x', ['heading'], ['heading', 'bodyText']);

        $this->assertSame('block', $binding?->blockRoot);
    }

    public function testVariablesTheTemplateDefinesAreNotTheEntry(): void
    {
        $binding = $this->resolver->parseSelf(self::HERO, '_blocks/hero.twig', [], self::HERO_FIELDS);

        $this->assertSame('block', $binding?->blockRoot);
        // `image` comes from `{% set %}` — not an entry, and its `url` is not
        // a field anyone should be told is missing.
        $this->assertArrayNotHasKey('url', $binding->args);
        $this->assertArrayHasKey('heroImage', $binding->args);
        $this->assertArrayHasKey('theme', $binding->args);
    }

    public function testANestedLoopBelongsToTheNestedEntryType(): void
    {
        $binding = $this->resolver->parseSelf(self::CARDS, '_blocks/cardsGrid.twig', [], ['heading', 'gridCards']);

        $this->assertSame('block', $binding?->blockRoot);
        $this->assertSame([
            'gridCards' => ['var' => 'card', 'sourceField' => 'gridCards', 'fields' => ['cardIcon', 'title', 'cardText']],
        ], $binding->nested);
        $this->assertArrayNotHasKey('cardIcon', $binding->args);
    }

    public function testStoriesThatAlreadyPassABlockAreLeftAsTheyWere(): void
    {
        // Someone may have worked around the old behaviour with
        // `args: { block: { heading: … } }`. That story sets `block` itself, so
        // nothing is stood in and the template renders exactly as before.
        $this->assertNull($this->resolver->parseSelf(self::HERO, '_blocks/hero.twig', ['block'], self::HERO_FIELDS));
    }

    public function testThePresentationalComponentIsNotSelfReading(): void
    {
        // `image.url` and `b.url` are property reads, but `image` is a story
        // argument and `b` a loop variable.
        $this->assertNull($this->resolver->parseSelf(
            self::PRESENTATIONAL,
            '_blocks/hero.twig',
            ['heading', 'image', 'buttons'],
            self::HERO_FIELDS,
        ));
    }

    public function testAVariableReadingNoFieldOfTheEntryTypeIsNotTheEntry(): void
    {
        // A story that happens to omit `image` must not turn the component into
        // a self-reading one: `image.url` and `image.alt` are no fields of the
        // matched entry type.
        $this->assertNull($this->resolver->parseSelf(
            self::PRESENTATIONAL,
            '_blocks/hero.twig',
            ['heading', 'buttons'],
            self::HERO_FIELDS,
        ));
    }

    public function testTheDispatcherChoosesTheName(): void
    {
        $source = '<h2>{{ item.heading }}</h2>{% if item.bodyText %}{{ item.bodyText }}{% endif %}';

        $this->assertSame('item', $this->resolver->parseSelf($source, 'x', [], ['heading', 'bodyText'])?->blockRoot);
    }

    public function testThePageEntryIsNotMistakenForTheBlock(): void
    {
        // Block templates often read the page too. The block is the variable
        // whose properties are the matched entry type's fields.
        $source = '<p>{{ entry.title }} · {{ entry.postDate|date }}</p><h2>{{ contentBlock.heading }}</h2>{{ contentBlock.bodyText }}';

        $binding = $this->resolver->parseSelf($source, 'x', [], ['heading', 'bodyText']);

        $this->assertSame('contentBlock', $binding?->blockRoot);
        $this->assertArrayNotHasKey('postDate', $binding->args);
    }

    public function testATieIsRefusedRatherThanGuessed(): void
    {
        $source = '{{ a.heading }} {{ b.heading }}';

        $this->assertNull($this->resolver->parseSelf($source, 'x', [], ['heading']));
    }

    public function testGlobalsAndStringsAreIgnored(): void
    {
        $source = <<<'TWIG'
            <p class="it's {{ 'block.fake' }}">{{ currentSite.name }} {{ craft.app.language }}</p>
            <h2>{{ block.heading }}</h2>
            {% for x in craft.entries.section('news').all() %}{{ x.title }}{% endfor %}
            TWIG;

        $binding = $this->resolver->parseSelf($source, 'x', [], ['heading']);

        $this->assertSame('block', $binding?->blockRoot);
        $this->assertSame(['heading'], array_keys($binding->args));
    }

    public function testMacroArgumentsAndImportsAreDefinedNames(): void
    {
        $source = <<<'TWIG'
            {% import '_macros' as ui %}
            {% macro card(entry, size = 'm') %}{{ entry.heading }}{{ size.label }}{% endmacro %}
            {{ ui.button(block.heading) }}
            TWIG;

        $binding = $this->resolver->parseSelf($source, 'x', [], ['heading']);

        $this->assertSame('block', $binding?->blockRoot);
    }

    private function component(array $storyArgs): ComponentDefinition
    {
        return new ComponentDefinition(
            id: 'c-hero',
            name: 'hero',
            title: 'Hero',
            templatePath: '_blocks/hero.twig',
            absoluteTemplatePath: '/dev/null',
            status: 'stable',
            stories: [new StoryDefinition(id: 'dark', name: 'Dark', title: 'Dark', args: $storyArgs)],
        );
    }

    public function testTheContractIsCheckedInFieldHandles(): void
    {
        $story = ['theme' => 'dark', 'heading' => 'Ride', 'heroImage' => '@image_1600x600', 'subtitle' => 'x'];
        // The entry type lacks `heroImage` and has `reversed`, which the
        // template never reads.
        $fields = ['eyebrow', 'heading', 'bodyText', 'theme', 'reversed', 'buttonLabel', 'buttonUrl'];
        $binding = $this->resolver->parseSelf(self::HERO, '_blocks/hero.twig', array_keys($story), $fields);
        $component = $this->component($story);

        $messages = array_map(
            static fn($error): string => $error->message,
            (new ContractChecker())->check($component, $binding, $fields),
        );

        $this->assertContains('Stories set “heroImage”, but this entry type has no such field.', $messages);
        $this->assertContains('Stories set “subtitle”, but the template never reads “block.subtitle”.', $messages);
        $this->assertContains('The entry type has a “reversed” field, but the template never reads it.', $messages);
        foreach ($messages as $message) {
            $this->assertStringNotContainsString('adapter', $message);
        }

        // …and the card an editor cannot reproduce is not offered.
        $this->assertSame([], (new ContractChecker())->producibleStories($component, $binding, $fields));
    }

    public function testAHealthyTemplateHasACleanContractAndEditableFields(): void
    {
        $story = ['theme' => 'dark', 'heading' => 'Ride', 'bodyText' => '<p>Hi</p>', 'heroImage' => '@image_1600x600'];
        $binding = $this->resolver->parseSelf(self::HERO, '_blocks/hero.twig', array_keys($story), self::HERO_FIELDS);
        $component = $this->component($story);

        $checker = new ContractChecker();
        $unfillable = array_filter(
            $checker->check($component, $binding, self::HERO_FIELDS),
            static fn($error): bool => !str_contains($error->message, 'never reads it'),
        );

        $this->assertSame([], array_values($unfillable));
        $this->assertSame(['dark'], $checker->producibleStories($component, $binding, self::HERO_FIELDS));

        // What a saved variant can keep: the same handles, nothing renamed.
        $editable = UserStoryBuilder::editableFields($binding, $component->stories);
        $this->assertSame('heading', $editable['heading'] ?? null);
        $this->assertSame('theme', $editable['theme'] ?? null);
    }
}

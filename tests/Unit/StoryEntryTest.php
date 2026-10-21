<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\preview\StandIns;
use b10k\componentguide\preview\StoryEntryBuilder;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Renders the self-reading Velo Studio blocks with a story entry, through real
 * Twig, in both modes a project can run in. On 19.10 the same templates with
 * plain story arrays rendered without a single error and without the cards,
 * the image, the dark theme or the rich text — so "it rendered" proves nothing
 * here; the assertions are on what came out.
 */
class StoryEntryTest extends TestCase
{
    private const HERO_TYPE = [
        'handle' => 'hero',
        'name' => 'Hero',
        'fields' => [
            'eyebrow' => ['kind' => 'text'],
            'heading' => ['kind' => 'text'],
            'bodyText' => ['kind' => 'html'],
            'theme' => ['kind' => 'option', 'options' => ['light' => 'Light', 'dark' => 'Dark']],
            'reversed' => ['kind' => 'bool'],
            'heroImage' => ['kind' => 'assets'],
            'buttonLabel' => ['kind' => 'text'],
            'buttonUrl' => ['kind' => 'text'],
        ],
    ];

    private const CARDS_TYPE = [
        'handle' => 'cardsGrid',
        'name' => 'Cards Grid',
        'fields' => [
            'heading' => ['kind' => 'text'],
            'gridCards' => ['kind' => 'matrix', 'types' => [
                'gridCard' => ['handle' => 'gridCard', 'name' => 'Grid Card', 'fields' => [
                    'cardIcon' => ['kind' => 'text'],
                    'cardText' => ['kind' => 'text'],
                ]],
            ]],
        ],
    ];

    private const HERO = <<<'TWIG'
        {% set image = block.heroImage.one() %}
        {% set dark = block.theme.value == 'dark' %}
        <section class="{{ dark ? 'bg-slate-900' : 'bg-orange-50' }}">
        {% if image %}<img src="{{ image.url }}" alt="">{% endif %}
        <h1>{{ block.heading }}</h1>
        {% if block.bodyText %}<div>{{ block.bodyText }}</div>{% endif %}
        </section>
        TWIG;

    private const CARDS = <<<'TWIG'
        {% for card in block.gridCards.all() %}<div class="card">{{ card.cardIcon }}|{{ card.title }}|{{ card.cardText }}</div>{% endfor %}
        count={{ block.gridCards|length }} first={{ block.gridCards.one().title }} two={{ block.gridCards.limit(2).all()|length }}
        TWIG;

    private function render(string $template, array $values, array $type, bool $strict, ?StandIns &$notes = null): string
    {
        $notes = new StandIns();
        $twig = new Environment(new ArrayLoader(['t' => $template]), [
            'strict_variables' => $strict,
            'autoescape' => 'html',
        ]);

        $args = $values;
        $args['block'] = (new StoryEntryBuilder($notes))->entry($values, $type, 'block');

        return $twig->render('t', $args);
    }

    private const HERO_DARK = [
        'theme' => 'dark',
        'heading' => 'Ride better',
        'bodyText' => '<p>Fitting and coaching.</p>',
        'heroImage' => 'https://example.test/photo.jpg',
    ];

    public function testHeroShowsImageThemeAndRichTextInBothModes(): void
    {
        foreach ([false, true] as $strict) {
            $html = $this->render(self::HERO, self::HERO_DARK, self::HERO_TYPE, $strict, $notes);

            $this->assertStringContainsString('bg-slate-900', $html, 'dark theme');
            $this->assertStringContainsString('<img src="https://example.test/photo.jpg"', $html, 'image');
            $this->assertStringContainsString('<div><p>Fitting and coaching.</p></div>', $html, 'rich text unescaped');
            $this->assertStringContainsString('<h1>Ride better</h1>', $html);
            $this->assertSame([], $notes->all());
        }
    }

    public function testAMissingImageIsAnEmptyQueryNotAnError(): void
    {
        $values = self::HERO_DARK;
        unset($values['heroImage'], $values['bodyText']);

        foreach ([false, true] as $strict) {
            $html = $this->render(self::HERO, $values, self::HERO_TYPE, $strict, $notes);

            $this->assertStringNotContainsString('<img', $html);
            $this->assertStringNotContainsString('<div>', $html);
            $this->assertSame([], $notes->all(), 'an empty field is not a gap in the preview');
        }
    }

    public function testThemeComparesAsItsValue(): void
    {
        $html = $this->render("{{ block.theme == 'dark' ? 'D' : 'L' }}{{ block.theme }}/{{ block.theme.label }}", self::HERO_DARK, self::HERO_TYPE, true);

        $this->assertSame('Ddark/Dark', $html);
    }

    public function testMatrixCardsRenderFromAPlainList(): void
    {
        $values = [
            'heading' => 'What we do',
            'gridCards' => [
                ['cardIcon' => '🚴', 'title' => 'Bike fitting', 'cardText' => 'Two hours on the rig.'],
                ['cardIcon' => '🛠️', 'title' => 'Servicing', 'cardText' => 'Strip and rebuild.'],
                ['cardIcon' => '☕', 'title' => 'Rides', 'cardText' => 'Back before lunch.'],
            ],
        ];

        foreach ([false, true] as $strict) {
            $html = $this->render(self::CARDS, $values, self::CARDS_TYPE, $strict, $notes);

            $this->assertStringContainsString('<div class="card">🚴|Bike fitting|Two hours on the rig.</div>', $html);
            $this->assertSame(3, substr_count($html, 'class="card"'));
            $this->assertStringContainsString('count=3 first=Bike fitting two=2', $html);
            $this->assertSame([], $notes->all());
        }
    }

    public function testAnUnknownPropertyIsEmptyAndNoted(): void
    {
        foreach ([false, true] as $strict) {
            $html = $this->render('[{{ block.subtitle }}]', self::HERO_DARK, self::HERO_TYPE, $strict, $notes);

            $this->assertSame('[]', $html);
            $this->assertSame(
                ['“block.subtitle” is not a field on “Hero” and not in the story, so the preview reads it as empty.'],
                $notes->all(),
            );
        }
    }

    public function testCommonAttributesAndGuardsWork(): void
    {
        $template = "{{ block.type.handle }}|{{ block.title }}|{{ block.fieldLayout.getFieldByHandle('heroImage') ? 'y' : 'n' }}|{{ block.fieldLayout.getFieldByHandle('nope') ? 'y' : 'n' }}";

        $html = $this->render($template, ['title' => 'Spring'] + self::HERO_DARK, self::HERO_TYPE, true, $notes);

        $this->assertSame('hero|Spring|y|n', $html);
        $this->assertSame([], $notes->all());
    }

    public function testNestedTypeComparesAsItsHandle(): void
    {
        // As written on a real project: `sliderBlocks|filter(bl => bl.type == 'blockTitle')`.
        // Craft's EntryType prints its handle, so the stand-in must too.
        $values = ['gridCards' => [['title' => 'A'], ['title' => 'B']]];

        $html = $this->render(
            "{{ (block.gridCards.all|filter(c => c.type == 'gridCard')|first).title }}|{{ block.gridCards.one.title }}|{{ block.gridCards.count() }}",
            $values,
            self::CARDS_TYPE,
            true,
            $notes,
        );

        $this->assertSame('A|A|2', $html);
        $this->assertSame([], $notes->all());
    }

    public function testAssetTransformsAreNotedNotFaked(): void
    {
        $html = $this->render("{{ block.heroImage.one().getUrl({ width: 300 }) }}", self::HERO_DARK, self::HERO_TYPE, true, $notes);

        $this->assertSame('https://example.test/photo.jpg', $html);
        $this->assertCount(1, $notes->all());
        $this->assertStringContainsString('transforms are not applied', $notes->all()[0]);
    }

    public function testQueryNarrowingIsSilentAndOtherCallsAreNoted(): void
    {
        $values = ['gridCards' => [['title' => 'A'], ['title' => 'B']]];

        $html = $this->render(
            "{{ block.gridCards.eagerly().orderBy('title').all()|length }}{{ block.gridCards.search('x').all()|length }}",
            $values,
            self::CARDS_TYPE,
            true,
            $notes,
        );

        $this->assertSame('22', $html);
        $this->assertCount(1, $notes->all());
        $this->assertStringContainsString('gridCards.search()', $notes->all()[0]);
    }

    public function testNestedItemsOfSeveralTypesSayWhichOneTheyAre(): void
    {
        $type = self::CARDS_TYPE;
        $type['fields']['gridCards']['types']['quote'] = ['handle' => 'quote', 'name' => 'Quote', 'fields' => [
            'quoteText' => ['kind' => 'html'],
        ]];
        $values = ['gridCards' => [
            ['type' => 'quote', 'quoteText' => '<em>Yes</em>'],
            ['title' => 'Untyped'],
        ]];

        $html = $this->render('{% for c in block.gridCards.all() %}[{{ c.type.handle }}:{{ c.quoteText ?? c.title }}]{% endfor %}', $values, $type, false, $notes);

        $this->assertSame('[quote:<em>Yes</em>][:Untyped]', $html);
        $this->assertStringContainsString('allows several entry types', $notes->all()[0]);
        // `??` falls back as it does on a real entry, but the read itself is
        // still a property the item does not have — the same fact the contract
        // check reports — so it is noted rather than hidden.
        $this->assertStringContainsString('“gridCards.quoteText” is not in the story', $notes->all()[1]);
    }

    public function testRelationsLinksAndOptionLists(): void
    {
        $type = ['handle' => 't', 'name' => 'T', 'fields' => [
            'related' => ['kind' => 'relation'],
            'cta' => ['kind' => 'link'],
            'tags' => ['kind' => 'options', 'options' => ['a' => 'Alpha', 'b' => 'Beta']],
            'count' => ['kind' => 'number'],
            'on' => ['kind' => 'bool'],
        ]];
        $values = [
            'related' => [['title' => 'Post', 'url' => '/post'], 'Other'],
            'cta' => ['url' => '/book', 'label' => 'Book'],
            'tags' => ['a', 'b'],
            'count' => '3',
            'on' => 1,
        ];

        $html = $this->render(
            "{% for r in block.related.all() %}{{ r.title }}{{ r.url }};{% endfor %}|{{ block.cta.url }}:{{ block.cta.label }}|{% for o in block.tags %}{{ o.label }}{% endfor %}{{ block.tags.contains('b') ? '+' : '-' }}|{{ block.count + 1 }}|{{ block.on ? 'on' : 'off' }}",
            $values,
            $type,
            true,
            $notes,
        );

        $this->assertSame('Post/post;Other;|/book:Book|AlphaBeta+|4|on', $html);
        $this->assertSame([], $notes->all());
    }
}

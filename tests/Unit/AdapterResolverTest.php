<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\services\AdapterResolver;
use PHPUnit\Framework\TestCase;

/**
 * The adapter is the only file that states which entry field feeds which
 * component argument. These fixtures are the real adapters from the Velo Studio
 * test site, kept verbatim: the parser has to cope with what people actually
 * write, not with a tidied-up version of it.
 */
class AdapterResolverTest extends TestCase
{
    private AdapterResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AdapterResolver();
    }

    private const HERO = <<<'TWIG'
        {# Adapter: maps a `hero` block entry onto _blocks/hero.twig. #}
        {% include '_blocks/hero.twig' with {
            eyebrow: block.eyebrow ?? null,
            heading: block.heading ?? '',
            bodyHtml: block.bodyText ?? null,
            imageUrl: block.fieldLayout.getFieldByHandle('media') and block.media.one() ? block.media.one().url : null,
            theme: block.theme.value ?? 'light',
            buttons: (block.buttonLabel ?? null) ? [{ label: block.buttonLabel, url: block.buttonUrl ?? '#' }] : [],
        } only %}
        TWIG;

    private const TESTIMONIALS = <<<'TWIG'
        {# Adapter: maps a `testimonials` block entry onto _blocks/testimonials.twig. #}
        {% include '_blocks/testimonials.twig' with {
            heading: block.heading ?? null,
            items: (block.fieldLayout.getFieldByHandle('quotes') ? block.quotes.all() : [])|map(it => {
                quote: it.quoteText ?? it.title,
                name: it.riderName ?? '',
                detail: it.detail ?? null,
            }),
        } only %}
        TWIG;

    /**
     * The other shape, taken from a real client project: one adapter for every
     * block type, handed the *parent* entry, looping its Matrix field and
     * switching on the type. Here `block` is the block entry itself — the thing
     * the first shape receives ready-made.
     */
    private const DISPATCHER = <<<'TWIG'
        {% for block in inlineComponentEntry.inlineComponents.site('*').all() %}
            {% if block.type == 'inlineNewsletterForm' %}
                {% include '_components/inline-components/inline-newsletter-form.twig' with {
                    heading: block.heading ?? null,
                    entryId: entry ? entry.id : null,
                } only %}
            {% elseif block.type == 'inlinePetitionForm' %}
                {% include '_components/inline-components/inline-petition-form.twig' with {
                    petitionQuery: block.petition ?? null,
                    heading: block.heading ?? null,
                    introText: block.introText ?? null,
                    utm_campaign: block.utmCampaign|length ? block.utmCampaign : null,
                    inlineComponentEntry: inlineComponentEntry,
                    entryUid: entry ? entry.uid : null,
                } only %}
            {% endif %}
        {% endfor %}
        TWIG;

    public function testItRecoversTheArgumentToFieldMappingTheStoryCannotShow(): void
    {
        $binding = $this->resolver->parse(self::HERO, '_blocks/hero.twig', '_adapters/hero.twig');

        self::assertNotNull($binding);
        self::assertSame('block', $binding->blockRoot);

        // The whole point: the story calls it bodyHtml, the field is bodyText,
        // and only the adapter knows they are the same thing.
        self::assertSame('bodyText', $binding->fieldFor('bodyHtml'));
        self::assertSame('eyebrow', $binding->fieldFor('eyebrow'));
        self::assertSame('heading', $binding->fieldFor('heading'));
        self::assertSame('theme', $binding->fieldFor('theme'));
    }

    public function testItReadsEveryTopLevelArgumentIncludingNestedAndTernaryValues(): void
    {
        $binding = $this->resolver->parse(self::HERO, '_blocks/hero.twig');

        self::assertNotNull($binding);
        self::assertSame(
            ['eyebrow', 'heading', 'bodyHtml', 'imageUrl', 'theme', 'buttons'],
            array_keys($binding->args),
        );
    }

    public function testAnArgumentBuiltFromTwoFieldsHasNoSingleSource(): void
    {
        $binding = $this->resolver->parse(self::HERO, '_blocks/hero.twig');

        self::assertNotNull($binding);
        // buttons reads buttonLabel and buttonUrl: prefilling it would mean
        // picking one, and picking is the guessing we are removing.
        self::assertNull($binding->fieldFor('buttons'));
        self::assertSame(['buttonLabel', 'buttonUrl'], $binding->blockFields['buttons']);
    }

    public function testGuardsOnTheFieldLayoutAreNotMistakenForFields(): void
    {
        $binding = $this->resolver->parse(self::HERO, '_blocks/hero.twig');

        self::assertNotNull($binding);
        // `block.fieldLayout` is read, but it is an element attribute, not a
        // field. Reporting it as missing would be a false alarm.
        self::assertContains('fieldLayout', $binding->blockFields['imageUrl']);
        self::assertFalse(AdapterResolver::isFieldHandle('fieldLayout'));
        self::assertTrue(AdapterResolver::isFieldHandle('media'));
    }

    public function testTitleCountsAsAlwaysPresent(): void
    {
        // An adapter falling back to it.title must never be reported as
        // referencing a field that does not exist.
        self::assertFalse(AdapterResolver::isFieldHandle('title'));
    }

    public function testArrowFunctionsBindTheNestedEntryAndItsFields(): void
    {
        $binding = $this->resolver->parse(self::TESTIMONIALS, '_blocks/testimonials.twig');

        self::assertNotNull($binding);
        self::assertSame('block', $binding->blockRoot);
        self::assertSame('heading', $binding->fieldFor('heading'));

        self::assertArrayHasKey('items', $binding->nested);
        self::assertSame('it', $binding->nested['items']['var']);

        // The fields below belong to the nested `quote` entry type, not to the
        // testimonials block — checking them against the block would be wrong.
        self::assertSame('quotes', $binding->nested['items']['sourceField']);
        self::assertSame(
            ['quoteText', 'title', 'riderName', 'detail'],
            $binding->nested['items']['fields'],
        );
    }

    public function testTheNestedVariableIsNotMistakenForTheBlock(): void
    {
        $binding = $this->resolver->parse(self::TESTIMONIALS, '_blocks/testimonials.twig');

        self::assertNotNull($binding);
        self::assertNotContains('quoteText', $binding->allBlockFields());
        self::assertNotContains('riderName', $binding->allBlockFields());
    }

    public function testAForLoopBindsItsVariableAndCollectsFieldsFromTheBody(): void
    {
        $source = <<<'TWIG'
            {% set rows = [] %}
            {% for row in block.gridCards.all() %}
                {{ row.cardText }} {{ row.cardIcon }}
            {% endfor %}
            {% include '_blocks/cardsGrid.twig' with {
                heading: block.heading,
                items: rows|map(r => { text: row.cardText }),
            } only %}
            TWIG;

        $binding = $this->resolver->parse($source, '_blocks/cardsGrid.twig');

        self::assertNotNull($binding);
        self::assertSame('block', $binding->blockRoot);
        self::assertArrayHasKey('items', $binding->nested);
        self::assertSame('gridCards', $binding->nested['items']['sourceField']);
        self::assertContains('cardIcon', $binding->nested['items']['fields']);
    }

    public function testTheBlockVariableIsObservedNotAssumed(): void
    {
        $source = <<<'TWIG'
            {% include '_blocks/hero.twig' with {
                heading: row.heading ?? '',
                bodyHtml: row.bodyText ?? null,
            } only %}
            TWIG;

        $binding = $this->resolver->parse($source, '_blocks/hero.twig');

        self::assertNotNull($binding);
        self::assertSame('row', $binding->blockRoot);
        self::assertSame('bodyText', $binding->fieldFor('bodyHtml'));
    }

    public function testAnIncludeWithoutOnlyIsRecorded(): void
    {
        $source = "{% include '_blocks/hero.twig' with { heading: block.heading } %}";

        $binding = $this->resolver->parse($source, '_blocks/hero.twig');

        self::assertNotNull($binding);
        self::assertFalse($binding->only);
    }

    public function testADottedStringLiteralIsNotAFieldRead(): void
    {
        $source = "{% include '_blocks/hero.twig' with { heading: block.heading|default('a.b') } only %}";

        $binding = $this->resolver->parse($source, '_blocks/hero.twig');

        self::assertNotNull($binding);
        self::assertSame(['heading'], $binding->blockFields['heading']);
    }

    public function testATemplateThatIncludesSomethingElseIsNotAnAdapterForThisComponent(): void
    {
        $binding = $this->resolver->parse(self::HERO, '_blocks/cardsGrid.twig');

        self::assertNull($binding);
    }

    public function testTheTwigExtensionIsOptionalWhenMatching(): void
    {
        self::assertNotNull($this->resolver->parse(self::HERO, '_blocks/hero'));
    }

    public function testADynamicIncludeIsIgnoredRatherThanGuessed(): void
    {
        $source = "{% include '_blocks/' ~ block.type.handle ~ '.twig' with { heading: block.heading } only %}";

        self::assertNull($this->resolver->parse($source, '_blocks/hero.twig'));
    }

    public function testArgumentsTheAdapterNeverSuppliesAreReported(): void
    {
        $binding = $this->resolver->parse(self::TESTIMONIALS, '_blocks/testimonials.twig');

        self::assertNotNull($binding);
        // A story showing an `eyebrow` on a block whose adapter never passes one
        // is a card promising something no editor can produce.
        self::assertSame(['eyebrow'], $binding->argsNeverSupplied(['heading', 'items', 'eyebrow']));
    }

    public function testALoopVariableSwitchedOnByTypeIsTheBlockItself(): void
    {
        $binding = $this->resolver->parse(
            self::DISPATCHER,
            '_components/inline-components/inline-petition-form.twig',
            '_components/_inline-component.twig',
        );

        self::assertNotNull($binding);

        // Read as an ordinary loop variable, `block` would be skipped and the
        // root would fall to `inlineComponentEntry` — making every field look
        // like it lived on the parent's Matrix field instead.
        self::assertSame('block', $binding->blockRoot);

        self::assertSame('petition', $binding->fieldFor('petitionQuery'));
        self::assertSame('heading', $binding->fieldFor('heading'));
        self::assertSame('introText', $binding->fieldFor('introText'));
        self::assertSame('utmCampaign', $binding->fieldFor('utm_campaign'));
    }

    public function testTheDispatcherShapeReportsNoNestedIteration(): void
    {
        $binding = $this->resolver->parse(
            self::DISPATCHER,
            '_components/inline-components/inline-petition-form.twig',
        );

        self::assertNotNull($binding);
        // The loop reads the parent's Matrix field to get the blocks; it is not
        // a repeater inside one. Reporting it as nested is what produced the
        // false badge on the client project.
        self::assertSame([], $binding->nested);
    }

    public function testArgumentsTheDispatcherFillsAreNotReportedAsUnsuppliable(): void
    {
        $binding = $this->resolver->parse(
            self::DISPATCHER,
            '_components/inline-components/inline-petition-form.twig',
        );

        self::assertNotNull($binding);
        // The regression in one line: before the fix every one of these was
        // called unproducible, and the gallery offered the editor nothing.
        self::assertSame(
            [],
            $binding->argsNeverSupplied(['petitionQuery', 'heading', 'introText', 'utm_campaign']),
        );
    }

    public function testAGenuineNestedLoopIsStillResolvedAlongsideADispatch(): void
    {
        // The same repeater as testAForLoopBindsItsVariableAndCollectsFieldsFromTheBody,
        // only wrapped in a dispatcher. Two loop variables now, and they must be
        // told apart: `block` is the entry, `row` is a row inside it.
        $source = <<<'TWIG'
            {% for block in entry.pageBlocks.all() %}
                {% if block.type == 'cardsGrid' %}
                    {% for row in block.gridCards.all() %}
                        {{ row.cardText }} {{ row.cardIcon }}
                    {% endfor %}
                    {% include '_blocks/cardsGrid.twig' with {
                        heading: block.heading,
                        items: rows|map(r => { text: row.cardText }),
                    } only %}
                {% endif %}
            {% endfor %}
            TWIG;

        $binding = $this->resolver->parse($source, '_blocks/cardsGrid.twig');

        self::assertNotNull($binding);
        self::assertSame('block', $binding->blockRoot);
        self::assertSame('heading', $binding->fieldFor('heading'));

        // Dropping `block` must not take `row` with it.
        self::assertArrayHasKey('items', $binding->nested);
        self::assertSame('row', $binding->nested['items']['var']);
        self::assertSame('gridCards', $binding->nested['items']['sourceField']);
        self::assertContains('cardIcon', $binding->nested['items']['fields']);
    }
}

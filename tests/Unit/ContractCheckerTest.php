<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\services\AdapterResolver;
use b10k\componentguide\services\ContractChecker;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance for the contract check, built from the Velo Studio test site as it
 * actually stands — adapters verbatim, field handles taken from its project
 * config. These are real defects that shipped: the gallery there offers editors
 * a hero with a photograph, on an install with no image field anywhere.
 */
class ContractCheckerTest extends TestCase
{
    private ContractChecker $checker;
    private AdapterResolver $resolver;

    protected function setUp(): void
    {
        $this->checker = new ContractChecker();
        $this->resolver = new AdapterResolver();
    }

    private const HERO_ADAPTER = <<<'TWIG'
        {% include '_blocks/hero.twig' with {
            eyebrow: block.eyebrow ?? null,
            heading: block.heading ?? '',
            bodyHtml: block.bodyText ?? null,
            imageUrl: block.fieldLayout.getFieldByHandle('media') and block.media.one() ? block.media.one().url : null,
            theme: block.theme.value ?? 'light',
            buttons: (block.buttonLabel ?? null) ? [{ label: block.buttonLabel, url: block.buttonUrl ?? '#' }] : [],
        } only %}
        TWIG;

    private const TESTIMONIALS_ADAPTER = <<<'TWIG'
        {% include '_blocks/testimonials.twig' with {
            heading: block.heading ?? null,
            items: (block.fieldLayout.getFieldByHandle('quotes') ? block.quotes.all() : [])|map(it => {
                quote: it.quoteText ?? it.title,
                name: it.riderName ?? '',
                detail: it.detail ?? null,
            }),
        } only %}
        TWIG;

    /** Field handles on Velo Studio's `hero` entry type. Note: no image field. */
    private const HERO_FIELDS = ['eyebrow', 'heading', 'bodyText', 'theme', 'buttonLabel', 'buttonUrl'];

    private function component(string $name, array $storyArgs): ComponentDefinition
    {
        return new ComponentDefinition(
            id: 'c-' . strtolower($name),
            name: $name,
            title: $name,
            templatePath: "_blocks/$name",
            absoluteTemplatePath: "/tmp/_blocks/$name.twig",
            stories: [new StoryDefinition(id: 'default', name: 'Default', title: 'Default', args: $storyArgs)],
        );
    }

    /** @param ScanError[] $errors */
    private function messagesOfType(array $errors, string $type): array
    {
        return array_values(array_map(
            static fn(ScanError $error): string => $error->message,
            array_filter($errors, static fn(ScanError $error): bool => $error->type === $type),
        ));
    }

    public function testItCatchesAStoryShowingAPhotographTheEntryTypeCannotHold(): void
    {
        $component = $this->component('hero', [
            'theme' => 'light',
            'eyebrow' => 'Velo Studio',
            'heading' => 'Ride better, not just harder',
            'bodyHtml' => '<p>…</p>',
            'imageUrl' => '@image_1600x600',
        ]);

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig', '_adapters/hero.twig');
        $errors = $this->checker->check($component, $binding, self::HERO_FIELDS);

        $unfillable = $this->messagesOfType($errors, ScanError::CONTRACT_UNFILLABLE_ARG);

        self::assertCount(1, $unfillable);
        self::assertStringContainsString('imageUrl', $unfillable[0]);
        self::assertStringContainsString('media', $unfillable[0]);
    }

    public function testTheMissingFieldIsNotAlsoReportedSeparately(): void
    {
        $component = $this->component('hero', ['imageUrl' => '@image_1600x600']);

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');
        $errors = $this->checker->check($component, $binding, self::HERO_FIELDS);

        // “media does not exist” and “imageUrl cannot be filled” are one fact.
        // The argument is the version a human recognises, so it wins.
        self::assertSame([], $this->messagesOfType($errors, ScanError::CONTRACT_UNKNOWN_FIELD));
    }

    public function testAStoryShowingTwoButtonsIsNotCaught(): void
    {
        // Documented limitation, asserted so it cannot regress quietly: the
        // story shows two buttons and the adapter can only ever build one, but
        // both field handles exist, and counting is beyond this version.
        $component = $this->component('hero', [
            'buttons' => [['label' => 'Book a fitting'], ['label' => 'See our sessions']],
        ]);

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');
        $errors = $this->checker->check($component, $binding, self::HERO_FIELDS);

        self::assertSame([], $this->messagesOfType($errors, ScanError::CONTRACT_UNFILLABLE_ARG));
    }

    public function testItCatchesAHeadingTheBlockHasNoFieldFor(): void
    {
        // Velo Studio's testimonials entry type holds only `quotes`, yet the
        // story shows a heading — so the section renders untitled on the page.
        $component = $this->component('testimonials', ['heading' => 'What riders say', 'items' => []]);

        $binding = $this->resolver->parse(self::TESTIMONIALS_ADAPTER, '_blocks/testimonials.twig');
        $errors = $this->checker->check($component, $binding, ['quotes'], ['quotes' => ['riderName', 'detail']]);

        $unfillable = $this->messagesOfType($errors, ScanError::CONTRACT_UNFILLABLE_ARG);

        self::assertCount(1, $unfillable);
        self::assertStringContainsString('heading', $unfillable[0]);
    }

    public function testItCatchesADeadFieldHandleInsideARepeater(): void
    {
        $component = $this->component('testimonials', ['items' => []]);

        $binding = $this->resolver->parse(self::TESTIMONIALS_ADAPTER, '_blocks/testimonials.twig');
        $errors = $this->checker->check($component, $binding, ['quotes'], ['quotes' => ['riderName', 'detail']]);

        $unknown = $this->messagesOfType($errors, ScanError::CONTRACT_UNKNOWN_FIELD);

        // Two dead handles here, both real: `quoteText` inside the repeater,
        // and `heading` on the block itself, which this entry type also lacks.
        $repeater = array_values(array_filter(
            $unknown,
            static fn(string $message): bool => str_contains($message, 'quoteText'),
        ));

        self::assertCount(1, $repeater);
        self::assertStringContainsString('quotes', $repeater[0]);
        self::assertNotEmpty(array_filter(
            $unknown,
            static fn(string $message): bool => str_contains($message, 'heading'),
        ));
    }

    public function testFallingBackToTitleIsNeverReported(): void
    {
        $component = $this->component('testimonials', ['items' => []]);

        $binding = $this->resolver->parse(self::TESTIMONIALS_ADAPTER, '_blocks/testimonials.twig');
        $errors = $this->checker->check($component, $binding, ['quotes'], ['quotes' => ['riderName', 'detail']]);

        foreach ($errors as $error) {
            self::assertStringNotContainsString('“title”', $error->message);
        }
    }

    public function testAFieldTheAdapterNeverPassesIsReportedAsUnused(): void
    {
        $component = $this->component('hero', ['heading' => 'x']);

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');
        $errors = $this->checker->check($component, $binding, [...self::HERO_FIELDS, 'subheading']);

        $unused = $this->messagesOfType($errors, ScanError::CONTRACT_UNUSED_FIELD);

        self::assertCount(1, $unused);
        self::assertStringContainsString('subheading', $unused[0]);
    }

    public function testAnArgumentTheAdapterNeverPassesIsReported(): void
    {
        $component = $this->component('hero', ['kicker' => 'Spring']);

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');
        $errors = $this->checker->check($component, $binding, self::HERO_FIELDS);

        $unfillable = $this->messagesOfType($errors, ScanError::CONTRACT_UNFILLABLE_ARG);

        self::assertCount(1, $unfillable);
        self::assertStringContainsString('kicker', $unfillable[0]);
    }

    public function testAnArgumentWithNoFieldBehindItIsFine(): void
    {
        $source = "{% include '_blocks/hero.twig' with { heading: block.heading, tone: 'quiet' } only %}";
        $component = $this->component('hero', ['heading' => 'x', 'tone' => 'quiet']);

        $binding = $this->resolver->parse($source, '_blocks/hero.twig');
        $errors = $this->checker->check($component, $binding, ['heading']);

        self::assertSame([], $this->messagesOfType($errors, ScanError::CONTRACT_UNFILLABLE_ARG));
    }

    public function testAComponentWithNoAdapterHasNoContractToBreak(): void
    {
        $component = $this->component('hero', ['imageUrl' => '@image_1600x600']);

        self::assertSame([], $this->checker->check($component, null, self::HERO_FIELDS));
    }

    public function testOnlyTheStoriesAnEditorCanReproduceAreOffered(): void
    {
        // Velo Studio's hero really does ship like this: two states built
        // around a background image, on an install with no image field, and a
        // third that happens not to need one. The gallery should be showing
        // the third — it is the only one an editor will ever see on the page.
        $component = new ComponentDefinition(
            id: 'c-hero',
            name: 'hero',
            title: 'Hero',
            templatePath: '_blocks/hero',
            absoluteTemplatePath: '/tmp/_blocks/hero.twig',
            stories: [
                new StoryDefinition(id: 'light', name: 'Light', title: 'Light', args: [
                    'theme' => 'light',
                    'heading' => 'Ride better',
                    'imageUrl' => '@image_1600x600',
                ]),
                new StoryDefinition(id: 'dark', name: 'Dark', title: 'Dark', args: [
                    'theme' => 'dark',
                    'heading' => 'Ride better',
                    'imageUrl' => '@image_1600x600',
                ]),
                new StoryDefinition(id: 'text-only', name: 'Text only', title: 'Text only', args: [
                    'theme' => 'light',
                    'heading' => 'Workshop hours',
                    'bodyHtml' => '<p>Tuesday to Saturday.</p>',
                ]),
            ],
        );

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');

        self::assertSame(
            ['text-only'],
            $this->checker->producibleStories($component, $binding, self::HERO_FIELDS),
        );
    }

    public function testWithNoAdapterEveryStoryStandsUnchallenged(): void
    {
        // No adapter means no contract, and no contract means no grounds to
        // withhold a state from the gallery.
        $component = $this->component('hero', ['imageUrl' => '@image_1600x600']);

        self::assertSame(
            ['default'],
            $this->checker->producibleStories($component, null, self::HERO_FIELDS),
        );
    }

    public function testTheBadgeAndTheGalleryAnswerFromTheSameRule(): void
    {
        // The index says this component promises something unreachable; the
        // gallery must not then offer that very state. One decision, two
        // consumers — the failure mode we fixed in 1.3.0, one level down.
        $component = $this->component('hero', ['imageUrl' => '@image_1600x600']);
        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');

        $flagged = $this->messagesOfType(
            $this->checker->check($component, $binding, self::HERO_FIELDS),
            ScanError::CONTRACT_UNFILLABLE_ARG,
        );

        self::assertNotEmpty($flagged);
        self::assertSame([], $this->checker->producibleStories($component, $binding, self::HERO_FIELDS));
    }

    public function testEveryStoryContributesItsArguments(): void
    {
        $component = new ComponentDefinition(
            id: 'c-hero',
            name: 'hero',
            title: 'Hero',
            templatePath: '_blocks/hero',
            absoluteTemplatePath: '/tmp/_blocks/hero.twig',
            stories: [
                new StoryDefinition(id: 'light', name: 'Light', title: 'Light', args: ['heading' => 'x']),
                // Only the second story shows the image — a switcher would put
                // this variant in front of editors too, so it has to be checked.
                new StoryDefinition(id: 'dark', name: 'Dark', title: 'Dark', args: ['imageUrl' => '@image_1600x600']),
            ],
        );

        $binding = $this->resolver->parse(self::HERO_ADAPTER, '_blocks/hero.twig');
        $errors = $this->checker->check($component, $binding, self::HERO_FIELDS);

        self::assertCount(1, $this->messagesOfType($errors, ScanError::CONTRACT_UNFILLABLE_ARG));
    }
}

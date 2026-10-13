<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\TwigLiteral;
use b10k\componentguide\helpers\UserStoryBuilder;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\services\AdapterResolver;
use PHPUnit\Framework\TestCase;

/**
 * An editor's own variant, read through the Velo Studio hero adapter as it
 * stands: one field per argument for most, a button list built from two
 * fields, and an image read through an asset relation.
 */
class UserStoryBuilderTest extends TestCase
{
    private const HERO_ADAPTER = <<<'TWIG'
        {% include '_blocks/hero.twig' with {
            eyebrow: block.eyebrow ?? null,
            heading: block.heading ?? '',
            bodyHtml: block.bodyText ?? null,
            imageUrl: block.fieldLayout.getFieldByHandle('heroImage') and block.heroImage.one() ? block.heroImage.one().url : null,
            theme: block.theme.value ?? 'light',
            buttons: (block.buttonLabel ?? null) ? [{ label: block.buttonLabel, url: block.buttonUrl ?? '#' }] : [],
            reversed: block.reversed ?? false,
        } only %}
        TWIG;

    /** @return StoryDefinition[] */
    private function stories(): array
    {
        return [
            new StoryDefinition('light', 'Light', 'Light', [
                'eyebrow' => 'Velo Studio',
                'heading' => 'Ride better, not just harder',
                'theme' => 'light',
                'buttons' => [['label' => 'Book a fitting', 'url' => '#']],
            ]),
            new StoryDefinition('dark', 'Dark', 'Dark', ['heading' => 'Ride better', 'theme' => 'dark']),
        ];
    }

    private function editable(): array
    {
        $binding = (new AdapterResolver())->parse(self::HERO_ADAPTER, '_blocks/hero.twig', '_adapters/hero.twig');
        $this->assertNotNull($binding);

        return UserStoryBuilder::editableFields($binding, $this->stories());
    }

    public function testOneFieldPerArgumentIsEditable(): void
    {
        $editable = $this->editable();

        $this->assertSame('heading', $editable['heading'] ?? null);
        $this->assertSame('theme', $editable['theme'] ?? null);
        $this->assertSame('reversed', $editable['reversed'] ?? null);
        $this->assertSame('eyebrow', $editable['eyebrow'] ?? null);
    }

    public function testAnArgumentBuiltFromTwoFieldsIsNot(): void
    {
        // `buttons` reads buttonLabel and buttonUrl: read backwards, a saved
        // label could not be told which argument it belongs to.
        $editable = $this->editable();

        $this->assertArrayNotHasKey('buttonLabel', $editable);
        $this->assertArrayNotHasKey('buttonUrl', $editable);
    }

    public function testValuesAreFilteredToEditableScalars(): void
    {
        $clean = UserStoryBuilder::cleanValues([
            'heading' => 'Mine',
            'theme' => 'dark',
            'reversed' => true,
            'buttonLabel' => 'Not editable',
            'eyebrow' => str_repeat('x', UserStoryBuilder::MAX_VALUE_LENGTH + 1),
            'unknown' => 'x',
        ], $this->editable());

        $this->assertSame(['heading' => 'Mine', 'theme' => 'dark', 'reversed' => true], $clean);
    }

    public function testDataUrisAreNeverKept(): void
    {
        $clean = UserStoryBuilder::cleanValues(['heading' => 'data:image/png;base64,AAAA'], ['heading' => 'heading']);

        $this->assertSame([], $clean);
    }

    public function testArgsAreTheBaseStoryWithTheSavedFieldsOverIt(): void
    {
        $args = UserStoryBuilder::args($this->stories()[0]->args, ['heading' => 'Coaching', 'theme' => 'dark'], $this->editable());

        $this->assertSame('Coaching', $args['heading']);
        $this->assertSame('dark', $args['theme']);
        // Untouched by the variant: still the base story's.
        $this->assertSame('Velo Studio', $args['eyebrow']);
        $this->assertSame([['label' => 'Book a fitting', 'url' => '#']], $args['buttons']);
    }

    public function testTitleIsTrimmedCollapsedAndCapped(): void
    {
        $this->assertSame('Coaching dark', UserStoryBuilder::title("  Coaching \n  dark "));
        $this->assertNull(UserStoryBuilder::title('   '));
        $this->assertSame(UserStoryBuilder::MAX_TITLE_LENGTH, mb_strlen((string)UserStoryBuilder::title(str_repeat('é', 200))));
    }

    public function testSnippetIsAStoryEntryInTwig(): void
    {
        $snippet = UserStoryBuilder::snippet("Coaching, Bohdan's", ['heading' => 'Hi', 'reversed' => false]);

        $this->assertSame(
            "'Coaching, Bohdan\\'s': {\n    args: {\n        heading: 'Hi',\n        reversed: false,\n    },\n},",
            $snippet,
        );
    }

    public function testTwigLiteralQuotesKeysOnlyWhenItMust(): void
    {
        $this->assertSame("{\n    plain: 1,\n    'two words': 'a\\\\b',\n}", TwigLiteral::value(['plain' => 1, 'two words' => 'a\\b']));
    }
}

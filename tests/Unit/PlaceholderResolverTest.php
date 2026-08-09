<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\services\PlaceholderResolver;
use PHPUnit\Framework\TestCase;

class PlaceholderResolverTest extends TestCase
{
    private PlaceholderResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new PlaceholderResolver();
    }

    public function testNonTokensPassThrough(): void
    {
        $args = [
            'heading' => 'Ride harder',
            'url' => '#',
            'count' => 3,
            'enabled' => true,
            'handle' => '@velostudio',   // unknown token → untouched
            'email' => 'hi@example.com',  // sigil not at the start → untouched
        ];

        $this->assertSame($args, $this->resolver->resolveArgs($args, 'seed'));
    }

    public function testLoremVariants(): void
    {
        $words = $this->resolver->resolveValue('@lorem_w_5', 'x');
        $this->assertCount(5, explode(' ', $words));
        $this->assertMatchesRegularExpression('/^[A-Z]/', $words, 'first word is capitalised');

        $sentences = $this->resolver->resolveValue('@lorem_s_3', 'x');
        $this->assertSame(3, substr_count($sentences, '.'));

        $paragraphs = $this->resolver->resolveValue('@lorem_p_2', 'x');
        $this->assertSame(2, substr_count($paragraphs, '<p>'));
        $this->assertStringEndsWith('</p>', $paragraphs);

        // Bare @lorem is a short phrase, not markup.
        $bare = $this->resolver->resolveValue('@lorem', 'x');
        $this->assertStringNotContainsString('<p>', $bare);
        $this->assertNotSame('', trim($bare));
    }

    public function testResultsAreDeterministicPerSeed(): void
    {
        $first = $this->resolver->resolveValue('@lorem_w_8', 'hero/Light.heading');
        $again = $this->resolver->resolveValue('@lorem_w_8', 'hero/Light.heading');
        $other = $this->resolver->resolveValue('@lorem_w_8', 'hero/Dark.heading');

        // Same seed → same text (no git noise, no flickering previews) …
        $this->assertSame($first, $again);
        // … different seed → different text.
        $this->assertNotSame($first, $other);
    }

    public function testLoopItemsDifferFromEachOther(): void
    {
        $args = $this->resolver->resolveArgs([
            'items' => [
                ['title' => '@lorem_w_3'],
                ['title' => '@lorem_w_3'],
                ['title' => '@lorem_w_3'],
            ],
        ], 'cards/Default');

        $titles = array_column($args['items'], 'title');
        $this->assertCount(3, array_unique($titles), 'sibling items get their own content');
    }

    public function testImageTokens(): void
    {
        $default = $this->resolver->resolveValue('@image', 'x');
        $this->assertStringStartsWith('https://picsum.photos/seed/', $default);
        $this->assertStringEndsWith('/800/600', $default);

        $sized = $this->resolver->resolveValue('@image_1600x600', 'x');
        $this->assertStringEndsWith('/1600/600', $sized);

        // Same seed → same photo.
        $this->assertSame($sized, $this->resolver->resolveValue('@image_1600x600', 'x'));
    }

    public function testIconResolvesToAnInlineDataUri(): void
    {
        // Craft's icon set isn't available in a unit context; the resolver must
        // still hand back something usable in an <img src>.
        $icon = $this->resolver->resolveValue('@icon_star', 'x');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $icon);
        $svg = base64_decode(substr($icon, strlen('data:image/svg+xml;base64,')));
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('xmlns=', $svg);
        $this->assertStringNotContainsString('currentColor', $svg);
    }
}

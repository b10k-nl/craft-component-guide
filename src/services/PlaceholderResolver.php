<?php

namespace b10k\componentguide\services;

use Craft;
use craft\helpers\Cp;
use yii\base\Component;

/**
 * Resolves placeholder tokens inside story args, so a story can say what kind
 * of content it needs instead of carrying the content itself:
 *
 *     heading:  '@lorem_w_6'
 *     bodyHtml: '@lorem_p_2'
 *     imageUrl: '@image_1600x600'
 *     iconUrl:  '@icon_star'
 *
 * Everything is DETERMINISTIC: values are derived from a seed built out of the
 * component, story and argument path, so the same story always renders the
 * same content (no git noise, no flickering previews, gallery thumbnails match
 * the detail page) while sibling items in a loop still differ from each other.
 *
 * Unknown `@foo` values are passed through untouched — the resolver only ever
 * replaces tokens it recognises.
 */
class PlaceholderResolver extends Component
{
    /**
     * Token prefix. `@` rather than `#` because `'#'` is already the
     * conventional placeholder for link args, and Craft developers read `@…`
     * as “something that gets resolved” (aliases).
     */
    public const SIGIL = '@';

    /** Classic lorem pool — recognisably placeholder text, never mistaken for copy. */
    private const WORDS = [
        'lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
        'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore',
        'et', 'dolore', 'magna', 'aliqua', 'enim', 'ad', 'minim', 'veniam',
        'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi',
        'aliquip', 'ex', 'ea', 'commodo', 'consequat', 'duis', 'aute', 'irure',
        'in', 'reprehenderit', 'voluptate', 'velit', 'esse', 'cillum', 'fugiat',
        'nulla', 'pariatur', 'excepteur', 'sint', 'occaecat', 'cupidatat',
        'non', 'proident', 'sunt', 'culpa', 'qui', 'officia', 'deserunt',
        'mollit', 'anim', 'id', 'est', 'laborum',
    ];

    /** @var string[]|null Craft's system icon names, lazily scanned. */
    private ?array $iconNames = null;

    /**
     * Walks an args array and resolves every token it contains.
     *
     * @param array<string, mixed> $args
     * @param string $seed Stable prefix identifying this story (e.g. "hero/Light").
     * @return array<string, mixed>
     */
    public function resolveArgs(array $args, string $seed = ''): array
    {
        /** @var array<string, mixed> $resolved */
        $resolved = $this->walk($args, $seed);

        return $resolved;
    }

    /**
     * Resolves a single value; non-strings and unknown tokens pass through.
     */
    public function resolveValue(mixed $value, string $seed = ''): mixed
    {
        if (!is_string($value) || !str_starts_with($value, self::SIGIL)) {
            return $value;
        }

        $token = substr($value, strlen(self::SIGIL));

        if ($token === 'lorem' || str_starts_with($token, 'lorem_')) {
            return $this->lorem($token, $seed);
        }
        if ($token === 'image' || str_starts_with($token, 'image_')) {
            return $this->image($token, $seed);
        }
        if ($token === 'icon' || str_starts_with($token, 'icon_')) {
            return $this->icon($token, $seed);
        }

        return $value;
    }

    private function walk(mixed $value, string $seed): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                // The path is part of the seed, so `items.1.title` differs from
                // `items.2.title` while staying stable across renders.
                $out[$key] = $this->walk($item, $seed . '.' . $key);
            }
            return $out;
        }

        return $this->resolveValue($value, $seed);
    }

    // -- Tokens ---------------------------------------------------------------

    private function lorem(string $token, string $seed): string
    {
        // lorem | lorem_w_N (words) | lorem_s_N (sentences) | lorem_p_N (paragraphs)
        if (preg_match('/^lorem_([wsp])_(\d{1,3})$/', $token, $m) !== 1) {
            return $this->words($this->pick(5, 8, $seed . ':len'), $seed);
        }

        $count = max(1, (int)$m[2]);

        if ($m[1] === 'w') {
            return $this->words($count, $seed);
        }
        if ($m[1] === 's') {
            return $this->sentences($count, $seed);
        }

        $paragraphs = [];
        for ($i = 0; $i < $count; $i++) {
            $paragraphs[] = '<p>' . $this->sentences($this->pick(2, 4, $seed . ":p$i:len"), $seed . ":p$i") . '</p>';
        }

        return implode("\n", $paragraphs);
    }

    private function words(int $count, string $seed): string
    {
        $words = [];
        for ($i = 0; $i < $count; $i++) {
            $words[] = self::WORDS[$this->hash($seed . ":w$i") % count(self::WORDS)];
        }
        $words[0] = ucfirst($words[0]);

        return implode(' ', $words);
    }

    private function sentences(int $count, string $seed): string
    {
        $sentences = [];
        for ($i = 0; $i < $count; $i++) {
            $sentences[] = $this->words($this->pick(6, 12, $seed . ":s$i:len"), $seed . ":s$i") . '.';
        }

        return implode(' ', $sentences);
    }

    private function image(string $token, string $seed): string
    {
        $width = 800;
        $height = 600;
        if (preg_match('/^image_(\d{2,5})x(\d{2,5})$/', $token, $m) === 1) {
            $width = (int)$m[1];
            $height = (int)$m[2];
        }

        // Picsum's /seed/ endpoint returns a stable photo per seed. If it can't
        // be reached (offline, locked-down staging), the preview document swaps
        // in an inline SVG of the same size — see preview/document.twig.
        return sprintf(
            'https://picsum.photos/seed/%s/%d/%d',
            substr(md5($seed), 0, 10),
            $width,
            $height,
        );
    }

    private function icon(string $token, string $seed): string
    {
        $name = null;
        if (preg_match('/^icon_([a-z0-9-]+)$/', $token, $m) === 1) {
            $name = $m[1];
        } else {
            $names = $this->iconNames();
            if ($names !== []) {
                $name = $names[$this->hash($seed) % count($names)];
            }
        }

        $svg = null;
        if ($name !== null) {
            try {
                $svg = Cp::iconSvg($name);
            } catch (\Throwable) {
                $svg = null;
            }
        }

        if ($svg === null || trim($svg) === '') {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">'
                . '<circle cx="12" cy="12" r="10" fill="#94a3b8"/></svg>';
        }

        // Craft's icons paint with currentColor, which means nothing inside an
        // <img>; bake in a neutral ink colour instead.
        $svg = str_replace('currentColor', '#334155', $svg);
        if (!str_contains($svg, 'xmlns=')) {
            $svg = str_replace('<svg', '<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        }

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * @return string[]
     */
    private function iconNames(): array
    {
        if ($this->iconNames !== null) {
            return $this->iconNames;
        }

        $this->iconNames = [];
        $dir = Craft::getAlias('@appicons/solid', false);
        if (is_string($dir) && is_dir($dir)) {
            foreach (glob($dir . '/*.svg') ?: [] as $file) {
                $this->iconNames[] = basename($file, '.svg');
            }
            sort($this->iconNames);
        }

        return $this->iconNames;
    }

    // -- Deterministic helpers -------------------------------------------------

    private function hash(string $seed): int
    {
        return abs(crc32($seed));
    }

    private function pick(int $min, int $max, string $seed): int
    {
        return $min + ($this->hash($seed) % max(1, $max - $min + 1));
    }
}

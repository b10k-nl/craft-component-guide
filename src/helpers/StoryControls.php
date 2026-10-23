<?php

namespace b10k\componentguide\helpers;

use b10k\componentguide\models\StoryDefinition;
use InvalidArgumentException;

/**
 * The controls on a component page: one input per argument, so whoever is
 * looking at a story — a developer, or a client reviewing a sprint on staging —
 * can change its values and watch the component answer, without a story file
 * for every case.
 *
 * Kept free of Craft so the rules can be tested on their own. The page builds
 * the list; the preview takes the changed values back through
 * {@see decodeOverrides()} and {@see withOverrides()}.
 *
 * What an input looks like is decided from the most specific thing known:
 * the block's field (for a template that reads the block itself), then the
 * values the template compares the argument against, then the values the
 * stories give it.
 */
final class StoryControls
{
    /** Changed values arrive in one POST field; anything bigger is not a story. */
    public const MAX_BYTES = 65536;

    public const MAX_DEPTH = 8;

    public const MAX_KEYS = 200;

    /** Longer than this, or on several lines, and a one-line input gets in the way. */
    private const LONG_TEXT = 80;

    private const MAX_SUGGESTIONS = 8;

    /**
     * @param StoryDefinition[] $stories All of the component's stories.
     * @param array<string, list<string>> $enums Argument => the string literals the template compares it with.
     * @param array<string, array<string, mixed>> $fields Argument => the block field it comes from, as
     *        {@see \b10k\componentguide\services\ContractInspector::entryTypeSchema()} describes one.
     * @param bool $blockFields True when the arguments *are* the block's fields (a template that reads
     *        the block itself): then every field gets a control, in the entry type's order, story or not.
     * @return list<array{name: string, label: ?string, kind: string, set: bool, value: mixed, options: array<array-key, string>, suggestions: list<string>, image: bool}>
     */
    public static function build(
        StoryDefinition $story,
        array $stories,
        array $enums = [],
        array $fields = [],
        bool $blockFields = false,
    ): array {
        // A block's fields first, in the order the entry type lists them —
        // the order an editor knows them in. Then whatever the stories add
        // (`title`, or a variable such as `blockIndex` the dispatcher passes).
        $names = [];
        if ($blockFields) {
            foreach (array_keys($fields) as $name) {
                $names[(string)$name] = true;
            }
        }
        foreach (array_merge([$story], $stories) as $s) {
            foreach (array_keys($s->args) as $name) {
                $names[(string)$name] = true;
            }
        }

        $controls = [];
        foreach (array_keys($names) as $name) {
            $name = (string)$name;
            $set = array_key_exists($name, $story->args);
            $value = $set ? $story->args[$name] : null;

            $seen = [];
            foreach (array_merge([$story], $stories) as $s) {
                if (array_key_exists($name, $s->args)) {
                    $seen[] = $s->args[$name];
                }
            }

            $spec = $fields[$name] ?? null;
            [$kind, $options] = is_array($spec) ? self::fromField($spec, $seen) : [self::fromValues($seen), []];

            // A plain text that the template only ever compares with a few
            // literals is a choice between them, whatever field it comes from.
            if ($kind === 'text' && isset($enums[$name])) {
                [$kind, $options] = ['select', self::enumOptions($enums[$name])];
            }

            // A select must be able to show the value it already has, even when
            // the template never compares against it.
            if ($kind === 'select' && is_scalar($value) && !is_bool($value) && !isset($options[(string)$value])) {
                $options[(string)$value] = (string)$value;
            }

            $controls[] = [
                'name' => $name,
                'label' => is_array($spec) && isset($spec['name']) && $spec['name'] !== '' ? (string)$spec['name'] : null,
                'kind' => $kind,
                'set' => $set,
                'value' => $value,
                'options' => $options,
                'suggestions' => in_array($kind, ['text', 'textarea'], true) ? self::suggestions($seen, $value) : [],
                // One image, by URL: offer the site's own assets next to the
                // input, so a client can try the block with their picture.
                'image' => $kind === 'text' && (
                    (is_array($spec) && ($spec['kind'] ?? null) === 'assets')
                    || self::looksLikeImage($seen)
                ),
            ];
        }

        return $controls;
    }

    /**
     * The changed values a preview request carries: a JSON object of argument
     * name => value. Anything else is refused rather than half-used.
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public static function decodeOverrides(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The changed values are too large to preview.');
        }

        try {
            $decoded = json_decode($raw, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('The changed values are not valid JSON.');
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('The changed values must be an object of argument names.');
        }
        if (count($decoded) > self::MAX_KEYS) {
            throw new InvalidArgumentException('Too many changed values.');
        }

        foreach (array_keys($decoded) as $key) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][\w-]*$/', $key) !== 1) {
                throw new InvalidArgumentException('An argument name in the changed values is not valid.');
            }
        }

        return $decoded;
    }

    /**
     * The story with the changed values written over its own. Top level only:
     * a list or a hash is changed as a whole, the way its control edits it.
     *
     * @param array<string, mixed> $overrides
     */
    public static function withOverrides(StoryDefinition $story, array $overrides): StoryDefinition
    {
        if ($overrides === []) {
            return $story;
        }

        return new StoryDefinition(
            $story->id,
            $story->name,
            $story->title,
            array_replace($story->args, $overrides),
            $story->description,
            $story->background,
            $story->viewport,
            $story->tags,
        );
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<mixed> $seen
     * @return array{0: string, 1: array<array-key, string>}
     */
    private static function fromField(array $spec, array $seen): array
    {
        $hasList = array_filter($seen, 'is_array') !== [];

        return match ($spec['kind'] ?? 'text') {
            'bool' => ['bool', []],
            'number' => ['number', []],
            'option' => ['select', array_map('strval', is_array($spec['options'] ?? null) ? $spec['options'] : [])],
            'html' => ['textarea', []],
            'options', 'matrix', 'relation' => ['json', []],
            'assets', 'link' => [$hasList ? 'json' : 'text', []],
            default => [self::fromValues($seen), []],
        };
    }

    /**
     * @param list<mixed> $seen
     */
    private static function fromValues(array $seen): string
    {
        $present = array_values(array_filter($seen, static fn($v) => $v !== null));
        if ($present === []) {
            return 'text';
        }

        if (array_filter($present, 'is_array') !== []) {
            return 'json';
        }
        if (array_filter($present, static fn($v) => !is_bool($v)) === []) {
            return 'bool';
        }
        if (array_filter($present, static fn($v) => !is_int($v) && !is_float($v)) === []) {
            return 'number';
        }

        foreach ($present as $v) {
            $v = (string)$v;
            if (str_contains($v, "\n") || mb_strlen($v) > self::LONG_TEXT || preg_match('/<[a-z\/]/i', $v) === 1) {
                return 'textarea';
            }
        }

        return 'text';
    }

    /**
     * @param list<string> $values
     * @return array<string, string>
     */
    private static function enumOptions(array $values): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[(string)$value] = (string)$value;
        }

        return $options;
    }

    /**
     * An image token (`@image_1600x600`), or a URL or root-relative path to an
     * image file, in any story.
     *
     * @param list<mixed> $seen
     */
    private static function looksLikeImage(array $seen): bool
    {
        foreach ($seen as $v) {
            if (is_string($v) && (
                str_starts_with($v, '@image')
                || preg_match('~^((https?:)?//\S+|/\S+)\.(jpe?g|png|gif|webp|avif|svg)(\?\S*)?$~i', $v) === 1
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the other stories put in a text argument — one click away, without
     * turning a free text into a fixed list.
     *
     * @param list<mixed> $seen
     * @return list<string>
     */
    private static function suggestions(array $seen, mixed $current): array
    {
        $out = [];
        foreach ($seen as $v) {
            if (!is_string($v) || $v === '' || $v === $current || mb_strlen($v) > self::LONG_TEXT) {
                continue;
            }
            $out[$v] = true;
            if (count($out) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return array_map('strval', array_keys($out));
    }
}

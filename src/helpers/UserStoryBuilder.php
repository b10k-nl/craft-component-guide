<?php

namespace b10k\componentguide\helpers;

use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\StoryDefinition;

/**
 * The rules for an editor's own variant of a block, kept free of Craft so they
 * can be tested on their own.
 *
 * A variant is a block an editor filled in and saved under a name. It is stored
 * as *field values*, not story args, because field values are what the editor
 * actually typed — and what the gallery puts back into a new block. The preview
 * needs args, so the adapter's own mapping is read backwards: a field that feeds
 * exactly one argument, and nothing else, overrides that argument in the story
 * the variant was based on.
 *
 * Only those fields are kept. That is the same set the gallery already knows how
 * to fill, so what a variant's card shows and what a click on it adds are one
 * and the same by construction — a variant can never preview a value it would
 * not put back.
 */
final class UserStoryBuilder
{
    /** Same limit as the gallery's prefill: longer text is content, not a variant. */
    public const MAX_VALUE_LENGTH = 500;

    public const MAX_TITLE_LENGTH = 80;

    /**
     * Field handle => component argument, for every field that can be saved.
     *
     * An argument qualifies when the adapter feeds it from exactly one block
     * field, not by iterating a nested field, and no story gives it a list or a
     * hash (a field cannot hold one). A field feeding two arguments is left out:
     * read backwards it would be a guess.
     *
     * @param StoryDefinition[] $stories
     * @return array<string, string>
     */
    public static function editableFields(AdapterBinding $binding, array $stories): array
    {
        $fields = [];
        $ambiguous = [];

        foreach (array_keys($binding->args) as $arg) {
            $arg = (string)$arg;
            if (isset($binding->nested[$arg])) {
                continue;
            }

            $handle = $binding->fieldFor($arg);
            if ($handle === null) {
                continue;
            }

            foreach ($stories as $story) {
                if (array_key_exists($arg, $story->args) && is_array($story->args[$arg])) {
                    continue 2;
                }
            }

            if (isset($fields[$handle])) {
                $ambiguous[$handle] = true;
                continue;
            }
            $fields[$handle] = $arg;
        }

        return array_diff_key($fields, $ambiguous);
    }

    /**
     * The values worth keeping: editable fields only, scalars only, and the same
     * length and data-URI rules as the gallery's prefill.
     *
     * @param array<mixed> $values
     * @param array<string, string> $editable
     * @return array<string, bool|int|float|string>
     */
    public static function cleanValues(array $values, array $editable): array
    {
        $clean = [];

        foreach ($values as $handle => $value) {
            $handle = (string)$handle;
            if (!isset($editable[$handle])) {
                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value)) {
                $clean[$handle] = $value;
            } elseif (is_string($value)
                && mb_strlen($value) <= self::MAX_VALUE_LENGTH
                && !str_starts_with($value, 'data:')
            ) {
                $clean[$handle] = $value;
            }
        }

        return $clean;
    }

    /**
     * The story args a variant renders with: the base story's, with every saved
     * field written over the argument it feeds.
     *
     * @param array<string, mixed> $baseArgs
     * @param array<string, mixed> $values
     * @param array<string, string> $editable
     * @return array<string, mixed>
     */
    public static function args(array $baseArgs, array $values, array $editable): array
    {
        $args = $baseArgs;

        foreach (self::cleanValues($values, $editable) as $handle => $value) {
            $args[$editable[$handle]] = $value;
        }

        return $args;
    }

    /**
     * A title fit for a card header, or null when there is nothing left of it.
     */
    public static function title(string $title): ?string
    {
        $title = trim((string)preg_replace('/\s+/u', ' ', $title));
        if ($title === '') {
            return null;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    /**
     * The variant as a story a developer pastes into the component's story file,
     * inside `{% set stories = { … } %}`. Complete on its own: every argument is
     * written out, not only the ones the editor changed, so it does not depend
     * on the story it was based on staying the same.
     *
     * @param array<string, mixed> $args
     */
    public static function snippet(string $title, array $args): string
    {
        return TwigLiteral::string($title) . ": {\n"
            . '    args: ' . TwigLiteral::value($args, 1) . ",\n"
            . '},';
    }
}

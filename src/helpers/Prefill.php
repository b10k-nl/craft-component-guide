<?php

namespace b10k\componentguide\helpers;

use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;

/**
 * A story as a block's starting content: field handle => value. What the
 * gallery fills a block with on insert, and what an agent on Craft's MCP
 * server is given to fill one with.
 */
final class Prefill
{
    /**
     * Scalar args become the block's starting content, keyed by the field they
     * actually belong to.
     *
     * The adapter states that mapping; until 1.4.0 the browser guessed it from
     * a naming convention of our own invention, so a project that named its
     * field anything else got an empty block and no explanation.
     *
     * @return array<string, mixed>
     */
    public static function fields(
        ComponentDefinition $component,
        StoryDefinition $story,
        ?AdapterBinding $binding,
    ): array {
        // Resolve with the same seed the preview uses, so the block a click
        // adds contains exactly the text its gallery card shows.
        $resolved = Plugin::getInstance()->getPlaceholderResolver()
            ->resolveArgs($story->args, $component->id . '/' . $story->id);

        $prefill = [];

        foreach ($resolved as $key => $value) {
            // An argument built from two fields has no single target, and
            // picking one of them is the guessing this replaced.
            $handle = $binding !== null ? $binding->fieldFor((string)$key) : (string)$key;
            if ($handle === null) {
                continue;
            }

            // Rich and nested values can't be mapped onto fields reliably, and
            // data-URI placeholders would paste a whole image into a text box.
            if (is_bool($value) || is_int($value) || is_float($value)) {
                $prefill[$handle] = $value;
            } elseif (is_string($value)
                && $value !== ''
                && mb_strlen($value) <= 500
                && !str_starts_with($value, 'data:')
            ) {
                $prefill[$handle] = $value;
            }
        }

        return $prefill;
    }
}

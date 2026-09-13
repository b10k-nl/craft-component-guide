<?php

namespace b10k\componentguide\models;

/**
 * What one adapter declares about a component: the mapping between the
 * component's arguments and the entry fields that feed them.
 *
 * A pure value object. It records what the adapter *says*; deciding whether
 * those fields exist belongs elsewhere.
 */
class AdapterBinding
{
    public function __construct(
        /** @var string Adapter path relative to the templates root. */
        public string $adapterPath,

        /**
         * Variable the adapter reads the block from — `block` by convention,
         * but the dispatcher chooses the name, so it is observed, not assumed.
         */
        public string $blockRoot = '',

        /** @var array<string, string> Component argument => raw Twig expression. */
        public array $args = [],

        /**
         * Component argument => field handles read on the block itself.
         * Usually one; a coalesce or ternary can name several.
         *
         * @var array<string, string[]>
         */
        public array $blockFields = [],

        /**
         * Arguments built by iterating a nested entry field, either through an
         * arrow function (`block.quotes.all()|map(it => { … })`) or a
         * `{% for … %}` loop. Per argument:
         *
         *   var         the iteration variable
         *   sourceField the block field being iterated, when it can be named
         *   fields      properties read on each nested entry
         *
         * These belong to the nested entry type, not to the block — checking
         * them against the block's field layout would be a false report.
         *
         * @var array<string, array{var: string, sourceField: string|null, fields: list<string>}>
         */
        public array $nested = [],

        /** Whether the include carries `only` — proof the component is presentational. */
        public bool $only = true,
    ) {
    }

    /**
     * The single block field an argument comes from, when there is exactly one.
     * Ambiguity returns null: a prefill that picks between two candidate fields
     * is the guessing this class exists to remove.
     */
    public function fieldFor(string $arg): ?string
    {
        $handles = $this->blockFields[$arg] ?? [];

        return count($handles) === 1 ? $handles[0] : null;
    }

    /**
     * Arguments the adapter never supplies: a story can show them, but no block
     * will ever fill them.
     *
     * @param string[] $storyArgs
     * @return list<string>
     */
    public function argsNeverSupplied(array $storyArgs): array
    {
        return array_values(array_diff($storyArgs, array_keys($this->args)));
    }

    /**
     * Every field handle read on the block itself.
     *
     * @return list<string>
     */
    public function allBlockFields(): array
    {
        $handles = [];
        foreach ($this->blockFields as $list) {
            foreach ($list as $handle) {
                $handles[$handle] = true;
            }
        }

        ksort($handles);

        return array_keys($handles);
    }
}

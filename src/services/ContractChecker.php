<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\ScanError;
use yii\base\Component;

/**
 * Checks that three things agree: what a story shows, what the adapter passes
 * on, and what fields the editor is actually given.
 *
 * Each of the three can be written correctly on its own and still combine into
 * a lie. A story shows a hero with a photograph; the adapter dutifully passes
 * an image *if the block has one*; the entry type has no image field. Nothing
 * is broken, nothing fails to render, and the gallery shows the editor a card
 * they can never reproduce.
 *
 * Rendering cannot catch this — the preview is flawless. Name matching cannot
 * catch it — the names line up. Only comparing the three lists can.
 *
 * Deliberately free of Craft: field handles arrive as plain arrays, so the
 * rules can be tested without a database.
 */
class ContractChecker extends Component
{
    /**
     * @param string[] $entryFields Field handles on the matched entry type.
     * @param array<string, string[]> $nestedFields Matrix field handle => field
     *        handles available on the entry types inside it.
     * @return ScanError[]
     */
    public function check(
        ComponentDefinition $component,
        ?AdapterBinding $binding,
        array $entryFields,
        array $nestedFields = [],
    ): array {
        // No adapter found is not a contract failure: plenty of components are
        // included directly, and a component with no block behind it has no
        // contract to break.
        if ($binding === null) {
            return [];
        }

        $errors = [];
        $blamedFields = [];

        foreach ($this->storyArgs($component) as $arg) {
            $error = $this->checkArg($component, $binding, $arg, $entryFields, $blamedFields);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        foreach ($this->unknownFields($binding, $entryFields, $nestedFields, $blamedFields) as $error) {
            $errors[] = $this->error($component, ScanError::CONTRACT_UNKNOWN_FIELD, $error);
        }

        foreach ($this->unusedFields($binding, $entryFields) as $handle) {
            $errors[] = $this->error(
                $component,
                ScanError::CONTRACT_UNUSED_FIELD,
                sprintf('The entry type has a “%s” field, but the adapter never passes it to the component.', $handle),
            );
        }

        return $errors;
    }

    /**
     * Every argument name any story sets, in first-seen order.
     *
     * @return list<string>
     */
    private function storyArgs(ComponentDefinition $component): array
    {
        $args = [];

        foreach ($component->stories as $story) {
            foreach (array_keys($story->args) as $arg) {
                $args[(string)$arg] = true;
            }
        }

        return array_keys($args);
    }

    /**
     * Story ids this component can actually be reproduced from — every argument
     * the story sets has a field behind it.
     *
     * The gallery uses this to preview what an editor will really get. Showing
     * a story they cannot produce is the defect this class was written for, and
     * putting it on the card is where they would meet it.
     *
     * @param string[] $entryFields
     * @return list<string>
     */
    public function producibleStories(
        ComponentDefinition $component,
        ?AdapterBinding $binding,
        array $entryFields,
    ): array {
        $ids = [];

        foreach ($component->stories as $story) {
            if ($binding === null) {
                // Nothing to check against: no adapter, so no contract, so no
                // grounds to withhold anything.
                $ids[] = $story->id;
                continue;
            }

            $producible = true;
            foreach (array_keys($story->args) as $arg) {
                if ($this->argFailure($binding, (string)$arg, $entryFields) !== null) {
                    $producible = false;
                    break;
                }
            }

            if ($producible) {
                $ids[] = $story->id;
            }
        }

        return $ids;
    }

    /**
     * Why an argument cannot be filled, or null when it can.
     *
     * The single place that decides it, so the badge on the index and the story
     * the gallery previews can never disagree.
     *
     * @param string[] $entryFields
     * @return array{kind: string, handles: list<string>}|null
     */
    private function argFailure(AdapterBinding $binding, string $arg, array $entryFields): ?array
    {
        if (!array_key_exists($arg, $binding->args)) {
            return ['kind' => 'not-passed', 'handles' => []];
        }

        // A nested argument is fed by iterating a field; the field is what has
        // to exist.
        $source = $binding->nested[$arg]['sourceField'] ?? null;
        if ($source !== null) {
            return in_array($source, $entryFields, true)
                ? null
                : ['kind' => 'nested', 'handles' => [$source]];
        }

        $handles = array_values(array_filter(
            $binding->blockFields[$arg] ?? [],
            AdapterResolver::isFieldHandle(...),
        ));

        // No field behind it at all: a constant or a computed value. Fine.
        if ($handles === []) {
            return null;
        }

        return array_intersect($handles, $entryFields) !== []
            ? null
            : ['kind' => 'missing', 'handles' => $handles];
    }

    /**
     * @param string[] $entryFields
     * @param array<string, true> $blamedFields
     */
    private function checkArg(
        ComponentDefinition $component,
        AdapterBinding $binding,
        string $arg,
        array $entryFields,
        array &$blamedFields,
    ): ?ScanError {
        $failure = $this->argFailure($binding, $arg, $entryFields);
        if ($failure === null) {
            return null;
        }

        foreach ($failure['handles'] as $handle) {
            $blamedFields[$handle] = true;
        }

        $quoted = implode(', ', array_map(
            static fn(string $handle): string => '“' . $handle . '”',
            $failure['handles'],
        ));

        $message = match ($failure['kind']) {
            'not-passed' => sprintf(
                'Stories set “%s”, but the adapter never passes it — nothing an editor fills will reach it.',
                $arg,
            ),
            'nested' => sprintf(
                'Stories set “%s”, which the adapter builds from a %s field the entry type does not have.',
                $arg,
                $quoted,
            ),
            default => sprintf(
                'Stories set “%s”, but the %s it comes from (%s) %s on this entry type.',
                $arg,
                count($failure['handles']) === 1 ? 'field' : 'fields',
                $quoted,
                count($failure['handles']) === 1 ? 'does not exist' : 'do not exist',
            ),
        };

        return $this->error($component, ScanError::CONTRACT_UNFILLABLE_ARG, $message);
    }

    /**
     * Field handles the adapter reads that the entry type does not have.
     *
     * Handles already blamed for an unfillable argument are skipped: the same
     * fact reported twice is noise, and the argument is the version an editor
     * would recognise.
     *
     * @param string[] $entryFields
     * @param array<string, string[]> $nestedFields
     * @param array<string, true> $blamedFields
     * @return list<string>
     */
    private function unknownFields(
        AdapterBinding $binding,
        array $entryFields,
        array $nestedFields,
        array $blamedFields,
    ): array {
        $messages = [];

        foreach ($binding->allBlockFields() as $handle) {
            if (!AdapterResolver::isFieldHandle($handle)
                || isset($blamedFields[$handle])
                || in_array($handle, $entryFields, true)
            ) {
                continue;
            }

            $messages[] = sprintf(
                'The adapter reads “%s”, which this entry type does not have, so it always falls back.',
                $handle,
            );
        }

        foreach ($binding->nested as $arg => $nested) {
            $source = $nested['sourceField'] ?? null;
            if ($source === null || isset($blamedFields[$source])) {
                continue;
            }

            // Unknown Matrix field: the argument check has already reported it,
            // or the field is not one we can resolve entry types for.
            if (!array_key_exists($source, $nestedFields)) {
                continue;
            }

            foreach ($nested['fields'] as $handle) {
                if (!AdapterResolver::isFieldHandle($handle)
                    || in_array($handle, $nestedFields[$source], true)
                ) {
                    continue;
                }

                $messages[] = sprintf(
                    'Building “%s”, the adapter reads “%s” on each “%s” entry, which those entry types do not have.',
                    $arg,
                    $handle,
                    $source,
                );
            }
        }

        return $messages;
    }

    /**
     * Fields the editor can fill that never reach the component.
     *
     * @param string[] $entryFields
     * @return list<string>
     */
    private function unusedFields(AdapterBinding $binding, array $entryFields): array
    {
        $read = $binding->allBlockFields();

        return array_values(array_filter(
            $entryFields,
            static fn(string $handle): bool => !in_array($handle, $read, true),
        ));
    }

    private function error(ComponentDefinition $component, string $type, string $message): ScanError
    {
        return new ScanError(
            type: $type,
            message: $message,
            componentId: $component->id,
        );
    }
}

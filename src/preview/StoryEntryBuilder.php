<?php

namespace b10k\componentguide\preview;

use Twig\Markup;

/**
 * Turns story values into the shapes Craft gives a template, field by field.
 *
 * The field type comes from the matched entry type (ContractInspector builds
 * the schema), so nothing here guesses from the value: a string is rich text
 * because the field is CKEditor, a list is a Matrix because the field is one.
 * That is what lets a story stay written the plain way — `heroImage:
 * '@image_1600x600'`, `theme: 'dark'`, `gridCards: [ {…}, {…} ]` — while the
 * template reads `heroImage.one().url`, `theme.value` and `gridCards.all()`.
 *
 * Schema per field: ['kind' => text|html|option|options|bool|number|assets|
 * matrix|relation|link, 'options' => [value => label], 'types' => [handle =>
 * entry-type schema]] — the last for Matrix fields.
 *
 * Free of Craft, so the conversions are tested on plain arrays.
 */
final class StoryEntryBuilder
{
    public function __construct(private StandIns $notes)
    {
    }

    /**
     * @param array<string, mixed> $values
     * @param array{handle: string, name: string, fields: array<string, array<string, mixed>>} $type
     */
    public function entry(array $values, array $type, string $label): StoryEntry
    {
        return new StoryEntry($values, $type, $this, $this->notes, $label);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function convert(mixed $value, array $spec, string $label): mixed
    {
        $short = str_contains($label, '.') ? substr($label, strrpos($label, '.') + 1) : $label;

        switch ($spec['kind'] ?? 'text') {
            case 'html':
                if ($value === null || $value === '') {
                    return null;
                }
                return $value instanceof Markup ? $value : new StoryRichText(is_scalar($value) ? (string)$value : '');

            case 'option':
                return $this->option($value, $spec);

            case 'options':
                $options = [];
                foreach ($this->list($value) as $item) {
                    $options[] = $this->option($item, $spec);
                }
                return new StoryOptions($options);

            case 'bool':
                return (bool)$value;

            case 'number':
                return is_numeric($value) ? $value + 0 : $value;

            case 'link':
                if (is_string($value) && $value !== '') {
                    return new StoryLink($value);
                }
                if (is_array($value) && isset($value['url'])) {
                    return new StoryLink((string)$value['url'], isset($value['label']) ? (string)$value['label'] : null);
                }
                return null;

            case 'assets':
                $assets = [];
                foreach ($this->list($value) as $item) {
                    if (is_string($item) && $item !== '') {
                        $assets[] = new StoryAsset($item, [], $this->notes, $short);
                    } elseif (is_array($item) && isset($item['url'])) {
                        $assets[] = new StoryAsset((string)$item['url'], $item, $this->notes, $short);
                    }
                }
                return new StoryQuery($assets, $this->notes, $short);

            case 'matrix':
            case 'relation':
                $entries = [];
                foreach ($this->list($value) as $item) {
                    if (is_string($item)) {
                        $item = ['title' => $item];
                    }
                    if (is_array($item)) {
                        $entries[] = $this->entry($item, $this->nestedType($item, $spec, $short), $short);
                    }
                }
                return new StoryQuery($entries, $this->notes, $short);

            default:
                return $value;
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function option(mixed $value, array $spec): StoryOption
    {
        if (is_array($value)) {
            $raw = $value['value'] ?? null;
            return new StoryOption($raw === null ? null : (string)$raw, isset($value['label']) ? (string)$value['label'] : null);
        }

        if ($value === null || $value === '') {
            return new StoryOption(null);
        }

        $string = is_scalar($value) ? (string)$value : '';
        $labels = $spec['options'] ?? [];

        return new StoryOption($string, isset($labels[$string]) ? (string)$labels[$string] : null);
    }

    /**
     * A story may give one item where a field holds many: `heroImage:
     * '@image…'` rather than `heroImage: ['@image…']`. Both mean the same.
     *
     * @return list<mixed>
     */
    private function list(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_is_list($value) ? $value : [$value];
        }

        return [$value];
    }

    /**
     * Which entry type a nested item is. One type in the Matrix field: that
     * one. Several: the item names it with `type: 'handle'`; otherwise its
     * values are read as they are and the preview says so.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $spec
     * @return array{handle: string, name: string, fields: array<string, array<string, mixed>>}
     */
    private function nestedType(array $item, array $spec, string $label): array
    {
        $types = $spec['types'] ?? [];

        if (isset($item['type']) && is_string($item['type']) && isset($types[$item['type']])) {
            return $types[$item['type']];
        }

        if (count($types) === 1) {
            return reset($types);
        }

        if (count($types) > 1) {
            $this->notes->note(sprintf(
                '“%s” allows several entry types and a story item does not say which (add `type: \'handle\'`), so its values are shown as given.',
                $label,
            ));
        }

        return ['handle' => '', 'name' => '', 'fields' => []];
    }
}

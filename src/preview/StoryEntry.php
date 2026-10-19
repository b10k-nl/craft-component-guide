<?php

namespace b10k\componentguide\preview;

/**
 * The block entry a self-reading template expects, built from a story.
 *
 * A template written the usual Craft way reads the entry the dispatcher hands
 * it — `block.heading`, `block.cards.all()`, `block.theme.value`. A story
 * cannot hand it a real entry, and a plain array answers only the first of
 * those. This answers all three, field by field, in the shape Craft gives each
 * field type (see StoryEntryBuilder), plus the attributes block templates
 * commonly touch.
 *
 * Anything else is answered with null — what a real entry without that value
 * gives — and written down in StandIns, so the preview says what it could not
 * show instead of quietly showing less.
 */
final class StoryEntry
{
    /** Attributes answered from the story when it sets them, else empty. */
    private const ATTRIBUTES = [
        'title' => '', 'slug' => '', 'url' => null, 'uri' => null, 'id' => null, 'uid' => null,
        'postDate' => null, 'dateCreated' => null, 'dateUpdated' => null, 'expiryDate' => null,
        'enabled' => true, 'status' => 'live', 'owner' => null, 'primaryOwner' => null,
        'sortOrder' => null, 'level' => null, 'siteId' => null, 'authorId' => null, 'author' => null,
        'ownerId' => null, 'primaryOwnerId' => null, 'fieldId' => null, 'typeId' => null,
        'sectionId' => null, 'canonicalId' => null, 'isDraft' => false, 'isRevision' => false,
    ];

    /** @var array<string, mixed> Converted field values, by handle. */
    private array $resolved = [];

    /**
     * @param array<string, mixed> $values Story values, keyed by field handle or attribute.
     * @param array{handle: string, name: string, fields: array<string, array<string, mixed>>} $type
     */
    public function __construct(
        private array $values,
        private array $type,
        private StoryEntryBuilder $builder,
        private StandIns $notes,
        private string $label,
    ) {
    }

    public function __isset(string $name): bool
    {
        return in_array($name, ['type', 'fieldLayout'], true)
            || array_key_exists($name, self::ATTRIBUTES)
            || isset($this->type['fields'][$name])
            || array_key_exists($name, $this->values);
    }

    public function __get(string $name): mixed
    {
        if ($name === 'type') {
            return $this->getType();
        }
        if ($name === 'fieldLayout') {
            return $this->getFieldLayout();
        }

        if (isset($this->type['fields'][$name])) {
            return $this->getFieldValue($name);
        }

        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        if (array_key_exists($name, self::ATTRIBUTES)) {
            return self::ATTRIBUTES[$name];
        }

        return $this->unknown($name);
    }

    public function getFieldValue(string $handle): mixed
    {
        if (!isset($this->type['fields'][$handle])) {
            return $this->values[$handle] ?? $this->unknown($handle);
        }

        if (!array_key_exists($handle, $this->resolved)) {
            $this->resolved[$handle] = $this->builder->convert(
                $this->values[$handle] ?? null,
                $this->type['fields'][$handle],
                $this->label . '.' . $handle,
            );
        }

        return $this->resolved[$handle];
    }

    public function getType(): StoryType
    {
        return new StoryType($this->type['handle'], $this->type['name']);
    }

    public function getFieldLayout(): StoryFieldLayout
    {
        return new StoryFieldLayout(array_keys($this->type['fields']));
    }

    public function getUrl(): ?string
    {
        $url = $this->values['url'] ?? null;

        return $url === null ? null : (string)$url;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        // Twig reaches here for `block.something` too, once __isset() has said
        // no — so this is both an unknown property and an unknown method.
        return $this->unknown($name);
    }

    public function __toString(): string
    {
        return (string)($this->values['title'] ?? '');
    }

    private function unknown(string $name): mixed
    {
        $this->notes->note($this->type['name'] !== ''
            ? sprintf('“%s.%s” is not a field on “%s” and not in the story, so the preview reads it as empty.', $this->label, $name, $this->type['name'])
            : sprintf('“%s.%s” is not in the story, so the preview reads it as empty.', $this->label, $name));

        return null;
    }
}

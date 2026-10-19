<?php

namespace b10k\componentguide\preview;

/**
 * A relation or Matrix field value. In a template that is an element query
 * (or, eager-loaded, a collection), and the template ends it with `.all()`,
 * `.one()`, `.first()`, `|length`, or loops it directly. A plain story array
 * answers none of those — they return null and the cards or the image
 * silently vanish from the preview.
 *
 * Narrowing calls (`limit`, `orderBy`, `with`, `eagerly`…) return the query
 * itself; `limit`/`offset` are honoured since they change what shows. Any
 * other call is noted rather than guessed at.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class StoryQuery implements \IteratorAggregate, \Countable
{
    /** Calls that only narrow or order a real query — harmless to ignore. */
    private const FLUENT = [
        'andwhere', 'anywhere', 'cache', 'eagerly', 'fixedorder', 'inreverse', 'orderby',
        'orwhere', 'relatedto', 'section', 'site', 'siteid', 'status', 'type', 'unique',
        'where', 'with', 'withcustomfields', 'prefersites', 'drafts',
        'provisionaldrafts', 'revisions', 'trashed', 'kind', 'volume', 'filename', 'field',
    ];

    /**
     * @param list<mixed> $items
     */
    public function __construct(
        private array $items,
        private StandIns $notes,
        private string $label,
    ) {
    }

    /** @return list<mixed> */
    public function all(): array
    {
        return $this->items;
    }

    public function one(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function last(): mixed
    {
        return $this->items === [] ? null : $this->items[count($this->items) - 1];
    }

    public function nth(int $n): mixed
    {
        return $this->items[$n] ?? null;
    }

    public function exists(): bool
    {
        return $this->items !== [];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isNotEmpty(): bool
    {
        return $this->items !== [];
    }

    /** @return list<mixed> */
    public function ids(): array
    {
        return [];
    }

    /** @return list<mixed> */
    public function collect(): array
    {
        return $this->items;
    }

    public function limit(mixed $limit): self
    {
        return $limit === null ? $this : new self(array_slice($this->items, 0, (int)$limit), $this->notes, $this->label);
    }

    public function offset(mixed $offset): self
    {
        return $offset === null ? $this : new self(array_slice($this->items, (int)$offset), $this->notes, $this->label);
    }

    public function count(mixed $q = '*', mixed $db = null): int
    {
        return count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        if (!in_array(strtolower($name), self::FLUENT, true)) {
            $this->notes->note(sprintf(
                '“%s.%s()” was ignored in the preview — the story values are shown as given.',
                $this->label,
                $name,
            ));
        }

        return $this;
    }
}

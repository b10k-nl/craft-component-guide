<?php

namespace b10k\componentguide\preview;

/**
 * A Checkboxes / Multi-select value, shaped like Craft's MultiOptionsFieldData:
 * iterable options, a count, `contains()`.
 *
 * @implements \IteratorAggregate<int, StoryOption>
 */
final class StoryOptions implements \IteratorAggregate, \Countable
{
    /**
     * @param list<StoryOption> $options
     */
    public function __construct(private array $options)
    {
    }

    public function contains(mixed $value): bool
    {
        foreach ($this->options as $option) {
            if ($option->value === (string)$value) {
                return true;
            }
        }

        return false;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->options);
    }

    public function count(): int
    {
        return count($this->options);
    }

    public function __toString(): string
    {
        return implode(', ', array_map(static fn(StoryOption $o): string => (string)$o, $this->options));
    }
}

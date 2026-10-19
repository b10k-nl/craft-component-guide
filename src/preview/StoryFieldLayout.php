<?php

namespace b10k\componentguide\preview;

/**
 * `block.fieldLayout`, for the guard templates use before reading an optional
 * field: `block.fieldLayout.getFieldByHandle('heroImage') ? …`.
 */
final class StoryFieldLayout
{
    /**
     * @param list<string> $handles
     */
    public function __construct(private array $handles)
    {
    }

    public function getFieldByHandle(string $handle): ?object
    {
        return in_array($handle, $this->handles, true) ? (object)['handle' => $handle] : null;
    }

    /** @return list<object> */
    public function getCustomFields(): array
    {
        return array_map(static fn(string $h): object => (object)['handle' => $h], $this->handles);
    }
}

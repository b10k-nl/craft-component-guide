<?php

namespace b10k\componentguide\preview;

/** `block.type` — handle and name, which is what dispatching templates read. */
final class StoryType
{
    public function __construct(
        public string $handle,
        public string $name,
    ) {
    }

    /**
     * The handle, as Craft's EntryType prints — which is what
     * `bl.type == 'blockTitle'` compares against in real templates.
     */
    public function __toString(): string
    {
        return $this->handle;
    }
}

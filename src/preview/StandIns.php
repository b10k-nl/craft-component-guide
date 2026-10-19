<?php

namespace b10k\componentguide\preview;

/**
 * What a preview had to stand in for, in words a developer can act on.
 *
 * A story entry (see StoryEntry) answers for the fields of the matched entry
 * type and the common element attributes. When a template asks for anything
 * else — a property no field carries, a method the stand-in does not know —
 * the answer is null, exactly what a real entry without that value would give.
 * Quietly, that would be the failure this namespace was written to end: a
 * preview missing part of the block with nothing saying so. So every such
 * answer is written down here and shown with the preview.
 */
final class StandIns
{
    /** @var array<string, true> */
    private array $notes = [];

    public function note(string $message): void
    {
        $this->notes[$message] = true;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_keys($this->notes);
    }
}

<?php

namespace b10k\componentguide\preview;

/**
 * A Dropdown / Radio Buttons / Button Group value, shaped like Craft's
 * SingleOptionFieldData: `.value`, `.label`, and the value when printed or
 * compared (`block.theme == 'dark'`).
 */
final class StoryOption
{
    public bool $selected;
    public bool $valid = true;

    public function __construct(
        public ?string $value,
        public ?string $label = null,
    ) {
        $this->label ??= $value;
        $this->selected = $value !== null;
    }

    public function __toString(): string
    {
        return (string)$this->value;
    }
}

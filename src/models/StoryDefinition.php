<?php

namespace b10k\componentguide\models;

/**
 * A single story (one example state) of a component.
 *
 * A pure value object: it holds no filesystem or rendering logic.
 */
class StoryDefinition
{
    public function __construct(
        /** @var string Deterministic, URL-safe ID (unique within its component). */
        public string $id,
        /** @var string Raw story key from the story file, e.g. "Primary". */
        public string $name,
        /** @var string Display title. */
        public string $title,
        /** @var array<string, mixed> Args passed to the Twig template. */
        public array $args = [],
        public ?string $description = null,
        public ?string $background = null,
        public ?string $viewport = null,
        /** @var string[] */
        public array $tags = [],
    ) {
    }
}

<?php

namespace b10k\componentguide\models;

/**
 * Receives the `meta` and `stories` variables declared by a Twig story file.
 *
 * Story templates are pure data (`{% set meta = {…} %}` / `{% set stories = {…} %}`);
 * TwigStoryLoader appends a hidden `{% do %}` that calls collect() with those
 * variables. The collected payload has the exact shape of the rich PHP story
 * format, so both formats normalize through the same StoryParser pipeline.
 */
class StoryCollector
{
    /** @var array<string, mixed> */
    private array $meta = [];

    /** @var array<string, mixed>|null Null until the template provided one. */
    private ?array $stories = null;

    /**
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $stories
     */
    public function collect(?array $meta, ?array $stories): void
    {
        $this->meta = $meta ?? [];
        $this->stories = $stories;
    }

    public function hasStories(): bool
    {
        return is_array($this->stories);
    }

    /**
     * @return array{meta: array<string, mixed>, stories: array<string, mixed>}
     */
    public function getData(): array
    {
        return ['meta' => $this->meta, 'stories' => $this->stories ?? []];
    }
}

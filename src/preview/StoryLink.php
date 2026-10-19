<?php

namespace b10k\componentguide\preview;

use Twig\Markup;

/**
 * A Link field value, shaped like Craft's LinkData: `.url`, `.label`, and the
 * URL when printed.
 */
final class StoryLink
{
    public function __construct(
        public string $url,
        public ?string $label = null,
    ) {
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getLabel(): string
    {
        return $this->label ?? $this->url;
    }

    public function getText(): string
    {
        return $this->getLabel();
    }

    public function getLink(): Markup
    {
        return new Markup(
            '<a href="' . htmlspecialchars($this->url, ENT_QUOTES) . '">' . htmlspecialchars($this->getLabel(), ENT_QUOTES) . '</a>',
            'UTF-8',
        );
    }

    public function __toString(): string
    {
        return $this->url;
    }
}

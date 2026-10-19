<?php

namespace b10k\componentguide\preview;

use Twig\Markup;

/**
 * An asset as a story describes it: a URL (usually an `@image…` placeholder)
 * and whatever else the story chose to give. Answers the parts of Craft's
 * Asset a block template normally touches.
 */
final class StoryAsset
{
    public ?string $title;
    public ?string $alt;
    public ?string $filename;
    public ?string $extension;
    public string $kind = 'image';
    public ?int $width;
    public ?int $height;
    public ?string $mimeType = null;
    public ?array $focalPoint = null;
    public ?int $id = null;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        public string $url,
        array $values,
        private StandIns $notes,
        private string $label,
    ) {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $base = $path !== '' && !str_starts_with($url, 'data:') ? basename($path) : null;

        $this->title = isset($values['title']) ? (string)$values['title'] : null;
        $this->alt = isset($values['alt']) ? (string)$values['alt'] : $this->title;
        $this->filename = isset($values['filename']) ? (string)$values['filename'] : $base;
        $this->extension = $this->filename !== null && str_contains($this->filename, '.')
            ? strtolower(pathinfo($this->filename, PATHINFO_EXTENSION))
            : null;
        $this->width = isset($values['width']) ? (int)$values['width'] : null;
        $this->height = isset($values['height']) ? (int)$values['height'] : null;
    }

    public function getUrl(mixed $transform = null): string
    {
        if ($transform !== null) {
            $this->notes->note(sprintf(
                'Image transforms are not applied in previews — “%s” shows the story image as it is.',
                $this->label,
            ));
        }

        return $this->url;
    }

    public function getImg(mixed $transform = null, ?array $sizes = null): Markup
    {
        $url = $this->getUrl($transform);

        return new Markup(
            '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars((string)$this->alt, ENT_QUOTES) . '">',
            'UTF-8',
        );
    }

    public function getSrcset(mixed $sizes = null, mixed $transform = null): string
    {
        return '';
    }

    public function getWidth(mixed $transform = null): ?int
    {
        return $this->width;
    }

    public function getHeight(mixed $transform = null): ?int
    {
        return $this->height;
    }

    public function getMimeType(mixed $transform = null): ?string
    {
        return $this->mimeType;
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->notes->note(sprintf(
            '“%s.%s” is not available on a story image, so the preview reads it as empty.',
            $this->label,
            $name,
        ));

        return null;
    }

    public function __toString(): string
    {
        return (string)($this->title ?? $this->filename ?? '');
    }
}

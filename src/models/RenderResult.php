<?php

namespace b10k\componentguide\models;

/**
 * The outcome of rendering a component template with a story's args.
 */
class RenderResult
{
    private function __construct(
        public bool $success,
        public string $html = '',
        public ?string $error = null,
        /** @var string|null Detail (trace/message); only surfaced in dev mode. */
        public ?string $details = null,
    ) {
    }

    public static function success(string $html): self
    {
        return new self(true, $html);
    }

    public static function failure(string $error, ?string $details = null): self
    {
        return new self(false, '', $error, $details);
    }
}

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
        /**
         * What the preview stood in for: values a self-reading template asked
         * for that no story or field could supply (see preview\StandIns).
         *
         * @var list<string>
         */
        public array $notes = [],
    ) {
    }

    /**
     * @param list<string> $notes
     */
    public static function success(string $html, array $notes = []): self
    {
        return new self(true, $html, null, null, $notes);
    }

    public static function failure(string $error, ?string $details = null): self
    {
        return new self(false, '', $error, $details);
    }
}

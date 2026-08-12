<?php

namespace b10k\componentguide\models;

/**
 * A structured, non-fatal error collected while scanning or parsing.
 *
 * One broken component must never prevent the rest of the guide from loading,
 * so scanner/parser problems are surfaced as ScanError values instead of thrown
 * exceptions.
 */
class ScanError
{
    public const MISSING_COMPONENT_DIRECTORY = 'missing_component_directory';
    public const INVALID_COMPONENT_DIRECTORY = 'invalid_component_directory';
    public const STORY_FILE_NOT_ARRAY = 'story_file_not_array';
    public const STORY_FILE_LOAD_ERROR = 'story_file_load_error';
    public const MISSING_TEMPLATE = 'missing_template';
    public const INVALID_STORY_FORMAT = 'invalid_story_format';
    public const DUPLICATE_COMPONENT_ID = 'duplicate_component_id';
    public const DUPLICATE_STORY_ID = 'duplicate_story_id';
    public const EMPTY_STORY_FILE = 'empty_story_file';
    public const TEMPLATE_RENDER_ERROR = 'template_render_error';
    public const UNKNOWN_STATUS = 'unknown_status';
    public const UNKNOWN_VIEWPORT = 'unknown_viewport';
    public const DUPLICATE_MARKER = 'duplicate_marker';

    public function __construct(
        public string $type,
        public string $message,
        public ?string $file = null,
        public ?string $componentId = null,
        public ?string $storyId = null,
        /** @var string|null Extra detail; only shown when Craft dev mode is on. */
        public ?string $details = null,
    ) {
    }

    /**
     * A short, human-friendly recommendation for resolving this error type.
     */
    public function recommendation(): string
    {
        return match ($this->type) {
            self::MISSING_COMPONENT_DIRECTORY => 'Create the configured component directory, or update the Component Path setting.',
            self::INVALID_COMPONENT_DIRECTORY => 'Point the Component Path setting at a readable folder inside your templates directory.',
            self::STORY_FILE_NOT_ARRAY, self::EMPTY_STORY_FILE => 'The story file must “return” a non-empty PHP array.',
            self::STORY_FILE_LOAD_ERROR => 'The story file threw an error while loading. Check its PHP syntax.',
            self::MISSING_TEMPLATE => 'Add a Twig template next to the story file with a matching base name.',
            self::INVALID_STORY_FORMAT => 'Each story must be an array of arguments, or a map with an “args” key.',
            self::DUPLICATE_COMPONENT_ID => 'Two components resolved to the same ID. Rename one of the templates.',
            self::DUPLICATE_STORY_ID => 'Two stories in this component share a name. Rename one of them.',
            self::TEMPLATE_RENDER_ERROR => 'The component template threw while rendering with these args.',
            self::UNKNOWN_STATUS => 'Use one of: ' . implode(', ', \b10k\componentguide\services\StoryParser::STATUSES) . '.',
            self::UNKNOWN_VIEWPORT => 'Use one of: ' . implode(', ', \b10k\componentguide\services\StoryParser::VIEWPORTS) . '.',
            self::DUPLICATE_MARKER => 'Keep a single marker file (GUIDE.md, BLOCKS.md or COMPONENTS.md) per directory.',
            default => '',
        };
    }
}

<?php

namespace b10k\componentguide\mcp;

use b10k\componentguide\helpers\Prefill;
use b10k\componentguide\helpers\StoryControls;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use b10k\componentguide\Plugin;
use Craft;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\mcp\attributes\RequiresPermission;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;

/**
 * Component Guide's tools on Craft's MCP server (Craft 5.12+): the blocks and
 * their stories, and a block rendered from the site's own templates — what an
 * agent cannot find out from the content model alone.
 *
 * Both read only. Shown to users who may open the guide (Plugin hides them
 * from anyone else); each call checks again.
 */
class Tools
{
    /** HTML returned at most; past it the markup is cut and says so. */
    public const MAX_HTML = 200000;

    private const ARGS_SCHEMA = [
        'type' => 'object',
        'description' => 'Values to change, keyed by the story\'s argument names — as Controls does on the component page. They replace the story\'s own; nothing is saved.',
        'additionalProperties' => true,
    ];

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'component-guide.stories',
        description: 'Lists the blocks Component Guide documents — entry type, status, whether editors can use it — or, for one block, its stories: sample values as the developer intended the block to be filled, with the field values to give a new block of that entry type (Pro).',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function stories(
        #[Schema(description: 'A component id or an entry type handle. Omit to list every block matched to an entry type, without story values.')]
        string $block = '',
    ): array {
        $this->guard();
        $plugin = Plugin::getInstance();
        $matcher = $plugin->getGalleryMatcher();

        if ($block === '') {
            $blocks = [];
            foreach ($plugin->getRepository()->getAll() as $component) {
                if ($matcher->matchedEntryTypeHandle($component) === null) {
                    continue;
                }
                $blocks[] = $this->summary($component) + [
                    'stories' => array_values(array_map(static fn(StoryDefinition $s): string => $s->title, $component->stories)),
                ];
            }

            return ['blocks' => $blocks];
        }

        $component = $this->component($block);
        $binding = $plugin->getContractInspector()->bindings(
            [$component],
            Craft::$app->getPath()->getSiteTemplatesPath(),
        )[$component->id] ?? null;
        $pro = $plugin->isPro();

        // Fields a value cannot simply be written into — assets, relations,
        // nested entries — are left out of `fields`: a story's stand-in
        // picture is a URL, and an asset field wants an asset.
        $handle = $matcher->matchedEntryTypeHandle($component);
        $schema = $handle !== null ? $plugin->getContractInspector()->entryTypeSchema($handle) : null;
        $kinds = array_map(static fn(array $f): string => $f['kind'], $schema['fields'] ?? []);

        $stories = [];
        $leftOut = [];
        foreach ($component->stories as $story) {
            $fields = null;
            if ($pro) {
                $fields = [];
                foreach (Prefill::fields($component, $story, $binding) as $field => $value) {
                    if (in_array($kinds[$field] ?? 'text', ['assets', 'relation', 'matrix'], true)) {
                        $leftOut[$field] = $kinds[$field];
                        continue;
                    }
                    $fields[$field] = $value;
                }
            }
            $stories[] = [
                'id' => $story->id,
                'title' => $story->title,
                'description' => $story->description,
                'args' => $story->args,
                'fields' => $fields,
            ];
        }

        $result = $this->summary($component) + ['stories' => $stories];
        if ($leftOut !== []) {
            $result['fieldsLeftOut'] = sprintf(
                'Not in `fields`: %s — their story values are stand-ins (a placeholder picture, sample entries). Choose real ones with Craft\'s own tools.',
                implode(', ', array_map(static fn(string $f, string $k): string => "$f ($k)", array_keys($leftOut), $leftOut)),
            );
        }
        if (!$pro) {
            $result['note'] = 'Field values for a new block (`fields`) come with Component Guide Pro. `args` are the template\'s own argument names, which may differ from the entry type\'s field handles.';
        }

        return ['blocks' => [$result]];
    }

    /**
     * @return array{block: string, story: string, ok: bool, html: string, truncated: bool, notes: list<string>, error: string|null, details: string|null, url: string}
     */
    #[McpTool(
        name: 'component-guide.render',
        description: 'Renders a block from the site\'s own Twig templates with a story\'s values, optionally changed, and returns the HTML — or the error the template throws. Use it to see what a block will look like before putting it on a page; the HTML is the block alone, without the site\'s CSS.',
        annotations: new ToolAnnotations(readOnlyHint: true),
    )]
    #[RequiresPermission('accessCp')]
    public function render(
        #[Schema(description: 'A component id or an entry type handle.')]
        string $block,
        #[Schema(description: 'A story id or title. Omit for the first story.')]
        string $story = '',
        #[Schema(definition: self::ARGS_SCHEMA)]
        array $args = [],
    ): array {
        $this->guard();
        $component = $this->component($block);
        $chosen = $this->story($component, $story);

        if ($args !== []) {
            try {
                $chosen = StoryControls::withOverrides($chosen, StoryControls::decodeOverrides(Json::encode($args)));
            } catch (InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage());
            }
        }

        $result = Plugin::getInstance()->getPreviewRenderer()->render($component, $chosen);
        $html = $result->html;
        $truncated = strlen($html) > self::MAX_HTML;
        if ($truncated) {
            $html = mb_strcut($html, 0, self::MAX_HTML);
        }

        return [
            'block' => $component->id,
            'story' => $chosen->id,
            'ok' => $result->success,
            'html' => $html,
            'truncated' => $truncated,
            'notes' => $result->notes,
            'error' => $result->error,
            'details' => $result->details,
            'url' => UrlHelper::cpUrl('component-guide/components/' . $component->id . '/' . $chosen->id),
        ];
    }

    /**
     * Discovery already hides these tools from anyone who may not open the
     * guide; a session that lost the permission since is told so.
     */
    private function guard(): void
    {
        if (!Plugin::canAccess()) {
            throw new ToolCallException('You do not have access to Component Guide.');
        }
    }

    /**
     * @return array{id: string, title: string, group: string, status: string|null, entryType: string|null, readyForEditors: bool}
     */
    private function summary(ComponentDefinition $component): array
    {
        $matcher = Plugin::getInstance()->getGalleryMatcher();

        return [
            'id' => $component->id,
            'title' => $component->title,
            'group' => $component->effectiveGroup(),
            'status' => $component->status,
            'entryType' => $matcher->matchedEntryTypeHandle($component),
            'readyForEditors' => $matcher->isReadyForEditors($component),
        ];
    }

    private function component(string $block): ComponentDefinition
    {
        $plugin = Plugin::getInstance();
        $component = $plugin->getRepository()->getById($block);
        if ($component !== null) {
            return $component;
        }

        $matcher = $plugin->getGalleryMatcher();
        foreach ($plugin->getRepository()->getAll() as $candidate) {
            $handle = $matcher->matchedEntryTypeHandle($candidate);
            if ($handle !== null && strcasecmp($handle, $block) === 0) {
                return $candidate;
            }
        }

        throw new ToolCallException(sprintf(
            'No block “%s”: give a component id or an entry type handle, as component-guide.stories lists them.',
            $block,
        ));
    }

    private function story(ComponentDefinition $component, string $story): StoryDefinition
    {
        if ($component->stories === []) {
            throw new ToolCallException(sprintf('“%s” has no stories to render.', $component->title));
        }
        if ($story === '') {
            return array_values($component->stories)[0];
        }
        foreach ($component->stories as $candidate) {
            if ($candidate->id === $story || strcasecmp($candidate->title, $story) === 0) {
                return $candidate;
            }
        }

        throw new ToolCallException(sprintf(
            '“%s” has no story “%s”. Its stories: %s.',
            $component->title,
            $story,
            implode(', ', array_map(static fn(StoryDefinition $s): string => $s->id, $component->stories)),
        ));
    }
}

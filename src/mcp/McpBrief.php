<?php

namespace b10k\componentguide\mcp;

/**
 * What Component Guide tells an agent connected to Craft's MCP server, in the
 * instructions `info.get` returns: which blocks the developer has marked as
 * ready for editors, so an agent filling a page uses those rather than
 * whatever entry types a Matrix field happens to accept.
 *
 * Kept free of Craft so the text can be tested; the plugin gathers the blocks.
 */
final class McpBrief
{
    /** The id of the contract check in `content-model.audit` (see ContractCheck). */
    public const CHECK_ID = 'component-guide-contract';

    /** Blocks listed at most; the rest are counted. Instructions are read on every connection. */
    public const MAX_BLOCKS = 60;

    /**
     * @param list<array{title: string, entryType: string, group: string, stories: list<string>, approved: list<string>}> $blocks
     *     Blocks ready for editors: stable, documented, matched to an entry type.
     *     `approved` lists the stories a client approved; only read when $review is on.
     * @param int $notReady Blocks matched to an entry type that are not ready (draft, beta, deprecated, no stories).
     * @param bool $review Client review is on: say which stories are approved.
     * @param bool $tools Our tools are on the server (see McpCompat).
     */
    public static function text(array $blocks, int $notReady, bool $review, bool $tools = true): string
    {
        $lines = [
            '## Component Guide',
            '',
            'Component Guide documents the block templates of this site: each block has stories — sample values — rendered as live previews in the control panel (Component Guide in the CP nav).',
        ];

        if ($blocks === []) {
            $lines[] = '';
            $lines[] = 'No block is marked as ready for editors yet.';
        } else {
            $lines[] = '';
            $lines[] = 'Blocks ready for editors — when you add nested entries (blocks) to a page, prefer these entry types. A story shows a block filled in as its developer intended:';
            // One group for the whole library says nothing on every line.
            $oneGroup = count(array_unique(array_column($blocks, 'group'))) === 1;
            foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $block) {
                $stories = $block['stories'];
                if ($review) {
                    $approved = array_flip($block['approved']);
                    $stories = array_map(
                        static fn(string $s): string => isset($approved[$s]) ? $s . ' (approved)' : $s,
                        $stories,
                    );
                }
                $lines[] = sprintf(
                    '- %s%s: entry type `%s`%s',
                    $block['title'],
                    $oneGroup ? '' : ' (' . $block['group'] . ')',
                    $block['entryType'],
                    $stories !== [] ? '; stories: ' . implode(', ', $stories) : '',
                );
            }
            $more = count($blocks) - self::MAX_BLOCKS;
            if ($more > 0) {
                $lines[] = sprintf('- and %d more.', $more);
            }
        }

        if ($notReady > 0) {
            $lines[] = '';
            $lines[] = sprintf(
                '%d more %s an entry type but %s not ready for editors (draft, beta, deprecated or without stories): avoid %s unless asked.',
                $notReady,
                $notReady === 1 ? 'block has' : 'blocks have',
                $notReady === 1 ? 'is' : 'are',
                $notReady === 1 ? 'it' : 'them',
            );
        }

        if ($review) {
            $lines[] = '';
            $lines[] = 'Client review is on: “approved” marks a story the client signed off. It is about how the block looks, not about page content.';
        }

        if ($tools) {
            $lines[] = '';
            $lines[] = 'Call `component-guide.stories` with an entry type handle for a block\'s stories and the field values to fill a new block with, and `component-guide.render` to see the HTML a block renders before you put it on a page.';
        }

        $lines[] = '';
        $lines[] = sprintf(
            'content-model.audit (admins) runs the check `%s`: stories that set a field the entry type does not have, and fields no template reads.',
            self::CHECK_ID,
        );

        return implode("\n", $lines);
    }
}

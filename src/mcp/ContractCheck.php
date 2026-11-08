<?php

namespace b10k\componentguide\mcp;

use b10k\componentguide\Plugin;
use Craft;
use craft\mcp\contentmodel\Check;

/**
 * The contract check — a story setting a field the entry type does not have,
 * a field no template reads — as a check of Craft's `content-model.audit`
 * (Craft 5.12+), next to unused fields and empty sections.
 *
 * Only loaded when Craft has MCP: Plugin registers it behind a class_exists().
 */
class ContractCheck implements Check
{
    public static function id(): string
    {
        return McpBrief::CHECK_ID;
    }

    /** @return array{count: int, findings: list<mixed>} */
    public function run(int $limit): array
    {
        $plugin = Plugin::getInstance();
        $components = $plugin->getRepository()->getAll();
        $errorsById = $plugin->getContractInspector()->inspect(
            $components,
            Craft::$app->getPath()->getSiteTemplatesPath(),
        );

        $matcher = $plugin->getGalleryMatcher();
        $names = [];
        foreach ($components as $component) {
            $names[$component->id] = [
                'title' => $component->title,
                'entryType' => (string)$matcher->matchedEntryTypeHandle($component),
            ];
        }

        $rows = ContractFindings::rows($errorsById, $names);

        return [
            'count' => count($rows),
            'findings' => array_slice($rows, 0, max(0, $limit)),
        ];
    }
}

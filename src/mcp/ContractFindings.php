<?php

namespace b10k\componentguide\mcp;

use b10k\componentguide\models\ScanError;

/**
 * The contract check's errors as `content-model.audit` findings: one row per
 * problem, the ones that break a block first.
 *
 * Kept free of Craft so it can be tested; ContractCheck feeds it.
 */
final class ContractFindings
{
    /** What an editor meets: a story they cannot reproduce, a field that goes nowhere. */
    private const ERRORS = [ScanError::CONTRACT_UNFILLABLE_ARG, ScanError::CONTRACT_UNKNOWN_FIELD];

    /**
     * @param array<string, ScanError[]> $errorsById component id => contract errors
     * @param array<string, array{title: string, entryType: string}> $components component id => what to name it by
     * @return list<array{component: string, title: string, entryType: string, story: string|null, type: string, severity: string, message: string}>
     */
    public static function rows(array $errorsById, array $components): array
    {
        $rows = [];
        foreach ($errorsById as $id => $errors) {
            $component = $components[$id] ?? ['title' => (string)$id, 'entryType' => ''];
            foreach ($errors as $error) {
                $rows[] = [
                    'component' => (string)$id,
                    'title' => $component['title'],
                    'entryType' => $component['entryType'],
                    'story' => $error->storyId,
                    'type' => $error->type,
                    'severity' => in_array($error->type, self::ERRORS, true) ? 'error' : 'notice',
                    'message' => $error->message,
                ];
            }
        }

        // Errors first; usort is stable, so otherwise in the order the components came.
        usort($rows, static fn(array $a, array $b): int => ($a['severity'] === 'error' ? 0 : 1) <=> ($b['severity'] === 'error' ? 0 : 1));

        return $rows;
    }
}

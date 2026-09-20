<?php

namespace b10k\componentguide\console\controllers;

use b10k\componentguide\models\ScanError;
use b10k\componentguide\Plugin;
use Craft;
use craft\helpers\Console;
use craft\helpers\Json;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Contract verification for CI.
 *
 * `php craft component-guide/stories/check`
 *
 * Answers one question with an exit code: do the stories, the adapters and the
 * entry types still agree? That agreement is the thing that rots quietly. A
 * field gets renamed during a content-model cleanup, a field is dropped from an
 * entry type, an adapter is rewritten — nothing throws, every preview still
 * renders, and the blocks gallery goes on offering editors a state they can no
 * longer produce. Six months later the person who built the library has left.
 *
 * ⚠️ This command deliberately renders NOTHING.
 *
 * A console request is not a site request, and Twig extensions that plugins
 * register only for site requests (Formie, Sprig, project modules) may not be
 * loaded here — the same trap that made control-panel previews fail on real
 * projects until 1.3.0, and it fails at *compile* time, so even an unreachable
 * branch kills the template. Worse, it fails inconsistently: an agent once
 * reported "34 of 34 render without error" from one request context while the
 * control panel disagreed from another. A number produced in the wrong context
 * is worse than no number, because it reads as verification.
 *
 * The contract check has none of that exposure. It reads the story file, the
 * adapter's include site and the entry type's field layout, and compares three
 * lists of names. Deterministic, fast, and it catches exactly the failure that
 * outlives the people who caused it.
 */
class StoriesController extends Controller
{
    /**
     * @var string Output format: "text" (default) or "json".
     */
    public string $format = 'text';

    /**
     * @var string Comma-separated error types that make the command fail.
     * Defaults to the two that mean the guide is lying to an editor. The
     * informational class (a field the adapter never reads) is reported but
     * does not fail the build unless asked for.
     */
    public string $failOn = 'contract_unfillable_arg,contract_unknown_field';

    public function options($actionID): array
    {
        return array_merge(
            parent::options($actionID),
            $actionID === 'check' ? ['format', 'failOn'] : [],
        );
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'f' => 'format',
        ]);
    }

    /**
     * Verifies that every story can be produced by the entry type behind it.
     *
     * Exit code 0 when nothing in `--fail-on` was found, 1 otherwise — so a CI
     * job can simply run it.
     */
    public function actionCheck(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->isPro()) {
            // Exit 0, deliberately. Someone who has not bought Pro has not
            // broken anything, and failing their build to advertise at them
            // would be a poor way to ask. They get told once, in one line, and
            // the pipeline carries on.
            $this->stdout(
                "Contract checking in CI is a Component Guide Pro feature.\n"
                . "The contract badge in the control panel is free and unchanged.\n",
                Console::FG_YELLOW,
            );

            return ExitCode::OK;
        }

        $components = $plugin->getRepository()->getAll();
        $templatesRoot = Craft::$app->getPath()->getSiteTemplatesPath();

        $errorsById = $plugin->getContractInspector()->inspect($components, $templatesRoot);

        // `inspect()` skips components with no matched entry type — without a
        // block behind them there is no contract. Counting all of them as
        // "checked" would inflate the coverage the exit code stands for, which
        // is the one number a CI job trusts.
        $matcher = $plugin->getGalleryMatcher();
        $checked = 0;
        foreach ($components as $component) {
            if ($matcher->matchedEntryTypeHandle($component) !== null) {
                $checked++;
            }
        }
        $skipped = count($components) - $checked;

        $failOn = $this->failOnTypes();
        $failing = 0;
        $reported = 0;

        /** @var array<string, list<array{type: string, message: string, story: string|null, fails: bool}>> $report */
        $report = [];

        foreach ($components as $component) {
            $found = $errorsById[$component->id] ?? [];
            if ($found === []) {
                continue;
            }

            $rows = [];
            foreach ($found as $error) {
                $fails = in_array($error->type, $failOn, true);
                $failing += $fails ? 1 : 0;
                $reported++;

                $rows[] = [
                    'type' => $error->type,
                    'message' => $error->message,
                    'story' => $error->storyId,
                    'fails' => $fails,
                ];
            }

            $report[$component->id] = $rows;
        }

        if (!in_array($this->format, ['text', 'json'], true)) {
            throw new \RuntimeException(sprintf(
                '“%s” is not a known --format. Use “text” or “json”.',
                $this->format,
            ));
        }

        if ($this->format === 'json') {
            $this->stdout(Json::encode([
                'checked' => $checked,
                'skipped' => $skipped,
                'reported' => $reported,
                'failing' => $failing,
                'components' => (object)$report,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

            return $failing > 0 ? ExitCode::DATAERR : ExitCode::OK;
        }

        return $this->reportText($report, $checked, $skipped, $reported, $failing);
    }

    /**
     * The findings that make the command fail, validated against the real
     * error types.
     *
     * An unrecognised value used to be accepted silently and simply match
     * nothing — so a single typo (`contract-unknown-field` for
     * `contract_unknown_field`) turned a CI gate into a green run with the
     * failures printed above it. A gate that cannot be trusted to fail is
     * worse than no gate, so an unknown name stops the command instead.
     *
     * @return list<string>
     * @throws \RuntimeException
     */
    private function failOnTypes(): array
    {
        $known = [
            ScanError::CONTRACT_UNFILLABLE_ARG,
            ScanError::CONTRACT_UNKNOWN_FIELD,
            ScanError::CONTRACT_UNUSED_FIELD,
        ];

        $requested = array_values(array_filter(
            array_map('trim', explode(',', $this->failOn)),
            static fn(string $type): bool => $type !== '',
        ));

        if ($requested === []) {
            throw new \RuntimeException(
                '--fail-on is empty. Pass at least one of: ' . implode(', ', $known) . '.',
            );
        }

        $unknown = array_diff($requested, $known);

        if ($unknown !== []) {
            throw new \RuntimeException(sprintf(
                'Unknown --fail-on %s: %s. Known types: %s.',
                count($unknown) === 1 ? 'type' : 'types',
                implode(', ', $unknown),
                implode(', ', $known),
            ));
        }

        return $requested;
    }

    /**
     * @param array<string, list<array{type: string, message: string, story: string|null, fails: bool}>> $report
     */
    private function reportText(array $report, int $checked, int $skipped, int $reported, int $failing): int
    {
        if ($report === []) {
            $this->stdout(sprintf(
                "Contract OK — %d component(s) checked%s.\n",
                $checked,
                $skipped > 0 ? sprintf(', %d skipped (no matching entry type)', $skipped) : '',
            ), Console::FG_GREEN);

            return ExitCode::OK;
        }

        foreach ($report as $componentId => $rows) {
            $this->stdout("\n" . $componentId . "\n", Console::FG_YELLOW);

            foreach ($rows as $row) {
                $this->stdout('  ' . ($row['fails'] ? '✖ ' : '· '), $row['fails']
                    ? Console::FG_RED
                    : Console::FG_GREY);
                $this->stdout($row['message'] . "\n");
            }
        }

        $this->stdout(sprintf(
            "\n%d component(s) checked%s, %d finding(s), %d failing.\n",
            $checked,
            $skipped > 0 ? sprintf(', %d skipped (no matching entry type)', $skipped) : '',
            $reported,
            $failing,
        ), $failing > 0 ? Console::FG_RED : Console::FG_GREEN);

        return $failing > 0 ? ExitCode::DATAERR : ExitCode::OK;
    }
}

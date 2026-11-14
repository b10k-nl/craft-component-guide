<?php

namespace b10k\componentguide\console\controllers;

use b10k\componentguide\helpers\AtomicFile;
use b10k\componentguide\helpers\ClientReview;
use b10k\componentguide\helpers\StoryAppender;
use b10k\componentguide\helpers\TwigLiteral;
use b10k\componentguide\helpers\UserStoryBuilder;
use b10k\componentguide\Plugin;
use Craft;
use craft\helpers\Console;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Brings client review decisions from staging into the story files.
 *
 * `php craft component-guide/reviews/import --file=component-guide-reviews-….json`
 * `ddev craft component-guide/reviews/import --file=- < ~/Downloads/component-guide-reviews-….json`
 *
 * Decisions are made on staging, where the client tries the blocks; the story
 * files live in git. "Export client reviews" on staging writes them to a
 * file; this command, run where the story files are written (usually
 * locally), takes the latest decision on each story:
 *
 * - approved → the story gets `approval: { by, date, version }` (or has its
 *   approval replaced), and nothing else in the file changes;
 * - changes requested → listed, as the work it is; an approval the story
 *   still has in the file is taken out — it is no longer the latest word;
 * - approved, but on an earlier version of the story (its template or its
 *   args) than the one here → not imported: it was given to a block that
 *   looks different.
 *
 * Nothing is rendered and nothing is written to the database. Importing the
 * same file twice changes nothing the second time.
 */
class ReviewsController extends Controller
{
    /**
     * @var bool Show what would be written, and write nothing.
     */
    public bool $dryRun = false;

    /**
     * @var string|null The exported file — or `-` to read it from standard
     * input, for a file the container cannot see:
     * `ddev craft component-guide/reviews/import --file=- < ~/Downloads/….json`
     */
    public ?string $file = null;

    /**
     * @var string|null For `clear`: only this component's history.
     */
    public ?string $component = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'import' => ['dryRun', 'file'],
            'clear' => ['component'],
            default => [],
        });
    }

    /**
     * Deletes the review decisions on one component (--component=<id>) or on
     * all — your own trial runs, before the client starts reviewing. Asks
     * first; `--interactive=0` does not. Approvals in the story files stay.
     */
    public function actionClear(): int
    {
        if (!Plugin::getInstance()->clientReviewEnabled()) {
            $this->stderr("Client review is off: turn it on in Settings → Plugins → Component Guide (Pro).\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }
        if ($this->component !== null && Plugin::getInstance()->getRepository()->getById($this->component) === null) {
            $this->stderr("No component “{$this->component}”.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $what = $this->component !== null ? "on “{$this->component}”" : 'on every component';
        if (!$this->confirm("Delete every review decision {$what}, by everyone? Export them first if you may need them.")) {
            return ExitCode::OK;
        }

        $removed = Plugin::getInstance()->getClientReviews()->clear($this->component);
        $this->stdout("{$removed} decision(s) deleted {$what}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), ['f' => 'file']);
    }

    /**
     * @param string|null $path The exported file; the same as --file.
     */
    public function actionImport(?string $path = null): int
    {
        if (!Plugin::getInstance()->clientReviewEnabled()) {
            $this->stderr("Client review is off: turn it on in Settings → Plugins → Component Guide (Pro).\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $file = $this->file ?? $path;
        if ($file === null || $file === '') {
            $this->stderr("Which file? --file=<exported file>, or --file=- to read it from standard input.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $json = $file === '-' ? (string)stream_get_contents(STDIN) : $this->read($file);
        if ($json === null) {
            return ExitCode::NOINPUT;
        }

        try {
            $imported = ClientReview::import($json);
        } catch (\InvalidArgumentException $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $repository = Plugin::getInstance()->getRepository();
        $templatesRoot = Craft::$app->getPath()->getSiteTemplatesPath();
        $approved = $already = $manual = $changes = $earlier = 0;

        // The latest decision on each story is where it stands; oldest
        // first, so the output reads in the order things were decided.
        $latest = array_values(ClientReview::latest($imported['reviews']));
        usort($latest, static fn(array $a, array $b): int => ClientReview::newestFirst($b, $a));

        // Where the decisions were made: the reviewer looks again there.
        $from = $imported['site'] ?? 'the site the file came from';
        if ($imported['environment'] !== null && $imported['environment'] !== $imported['site']) {
            $from .= " ({$imported['environment']})";
        }
        $this->stdout("Client reviews from {$from}" . ($imported['exported'] !== null ? ", exported {$imported['exported']}" : '') . ".\n");

        foreach ($latest as $review) {
            $component = $repository->getById($review['componentId']);
            $story = $component?->getStory($review['storyId']);
            $label = ($component !== null ? $component->title : $review['componentId']) . ' · ' . $review['storyTitle'];

            if ($component === null || $story === null) {
                $manual++;
                $this->stdout("\nNot here — {$label}: no such story in this project. Not imported.\n", Console::FG_RED);
                continue;
            }

            $source = is_file($component->absoluteTemplatePath) ? @file_get_contents($component->absoluteTemplatePath) : false;
            $state = ClientReview::state($review, null, is_string($source) ? ClientReview::fingerprint($source, $story->args) : null);
            $path = $templatesRoot . DIRECTORY_SEPARATOR . $component->storyFilePath;
            $twig = str_ends_with($path, '.twig') && is_file($path);

            if ($review['decision'] === ClientReview::APPROVED && $state['state'] !== ClientReview::STATE_APPROVED) {
                $earlier++;
                $this->stdout("\nNot imported — {$label}: {$review['by']} approved an earlier version — {$component->templatePath} or the story's args have changed since. Ask them to look again on {$from}.\n", Console::FG_YELLOW);
                continue;
            }

            if ($review['decision'] === ClientReview::APPROVED) {
                // By username: the user can always be found again by it.
                $approval = ['by' => $review['username'] ?? $review['by'], 'date' => $review['date'], 'version' => $review['version']];
                if ($story->approval !== null && $story->approval['by'] === $approval['by'] && $story->approval['date'] === $approval['date']) {
                    $already++;
                    continue;
                }
                $line = 'approval: ' . UserStoryBuilder::approvalLiteral($approval) . ',';
                $updated = null;
                $why = "{$component->storyFilePath} is not a Twig story file.";
                if ($twig) {
                    try {
                        $updated = StoryAppender::approve((string)file_get_contents($path), $story->title, $approval);
                    } catch (\RuntimeException $e) {
                        $why = $e->getMessage();
                    }
                }
                if ($updated === null || (!$this->dryRun && !$this->write($path, $updated))) {
                    $manual++;
                    $why = $updated === null ? $why : "{$component->storyFilePath} is not writable.";
                    $this->stdout("\n{$why} Add to “{$story->title}” in {$component->storyFilePath} by hand — {$label}:\n  {$line}\n", Console::FG_YELLOW);
                    continue;
                }
                $approved++;
                $this->stdout("\n" . ($this->dryRun ? 'Would approve' : 'Approved') . " “{$story->title}” in {$component->storyFilePath} — by {$review['by']}, {$review['date']}\n", Console::FG_GREEN);
                if ($review['comment'] !== '') {
                    $this->stdout('  Comment: ' . str_replace("\n", "\n           ", $review['comment']) . "\n");
                }
                continue;
            }

            // Changes requested: the work. Say what, and what they tried.
            $changes++;
            // Numbered: the requests are the work, to be ticked off.
            $this->stdout("\n{$changes}. Changes requested — {$label}, by {$review['by']}, {$review['date']}\n", Console::FG_YELLOW);
            $this->stdout('  ' . str_replace("\n", "\n  ", $review['comment']) . "\n");
            foreach ($this->tried($story->args, $review['args']) as $name => $value) {
                $this->stdout("  Tried {$name}: {$value}\n");
            }
            if ($state['reason'] === ClientReview::REASON_FIXED && $review['fixed'] !== null) {
                $this->stdout("  Marked as fixed by {$review['fixed']['by']}, {$review['fixed']['date']} — waiting for {$review['by']} to look again.\n");
            } elseif ($state['reason'] === ClientReview::REASON_UPDATED) {
                $this->stdout("  The block or the story has changed here since — waiting for {$review['by']} to look again.\n");
            }

            // An approval still in the file is no longer the latest word.
            if ($story->approval !== null && $twig) {
                try {
                    $updated = StoryAppender::removeApproval((string)file_get_contents($path), $story->title);
                    if ($this->dryRun || $this->write($path, $updated)) {
                        $this->stdout('  ' . ($this->dryRun ? 'Would take out' : 'Took out') . " the approval by {$story->approval['by']}, {$story->approval['date']}, from {$component->storyFilePath}.\n");
                        continue;
                    }
                } catch (\RuntimeException) {
                    // Said below.
                }
            }
            if ($story->approval !== null) {
                $manual++;
                $this->stdout("  Take the approval out of “{$story->title}” in {$component->storyFilePath} by hand.\n", Console::FG_YELLOW);
            }
        }

        $this->stdout(sprintf(
            "\n%d %s, %d already in the story files, %d requests for changes, %d approved on an earlier version (not imported), %d to do by hand%s.\n",
            $approved,
            $this->dryRun ? 'would be approved' : 'approved',
            $already,
            $changes,
            $earlier,
            $manual,
            $imported['skipped'] > 0 ? sprintf(', %d entries in the file were not decisions and were skipped', $imported['skipped']) : '',
        ));

        if ($approved > 0 && !$this->dryRun) {
            $this->stdout("Review the changes and commit them.\n");
        }

        return ExitCode::OK;
    }

    /**
     * The values a request for changes carries that differ from the story's,
     * each as a short Twig literal.
     *
     * @param array<string, mixed> $story
     * @param array<string, mixed> $tried
     * @return array<string, string>
     */
    private function tried(array $story, array $tried): array
    {
        $out = [];
        foreach ($tried as $name => $value) {
            if (!array_key_exists($name, $story) || !ClientReview::sameArgs(['v' => $story[$name]], ['v' => $value])) {
                $literal = TwigLiteral::value($value);
                $out[(string)$name] = mb_strlen($literal) > 200 ? mb_substr($literal, 0, 200) . '…' : $literal;
            }
        }

        return $out;
    }

    /**
     * The file's contents, or null after saying why not. A path the command
     * cannot see is most often a host path given to a container — say so.
     */
    private function read(string $file): ?string
    {
        if (is_file($file) && is_readable($file)) {
            return (string)file_get_contents($file);
        }

        $this->stderr("Cannot read {$file}.\n", Console::FG_RED);
        if (getenv('IS_DDEV_PROJECT') === 'true' || is_file('/.dockerenv')) {
            $this->stderr("This command runs in a container, which sees only the project folder. Read the file from your machine instead:\n");
            $this->stderr('  ddev craft component-guide/reviews/import' . ($this->dryRun ? ' --dry-run' : '') . " --file=- < \"{$file}\"\n");
        }

        return null;
    }

    /**
     * Writes a story file whole or not at all (see AtomicFile): an import
     * cut off halfway must not leave the developer's file empty.
     */
    private function write(string $path, string $contents): bool
    {
        if (!is_writable($path)) {
            return false;
        }
        try {
            AtomicFile::replace($path, $contents);
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}

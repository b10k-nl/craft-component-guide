<?php

namespace b10k\componentguide\helpers;

/**
 * The rules for a client's review of a block, kept free of Craft so they can
 * be tested on their own.
 *
 * A review is a decision on one story of one component: *approved* — the
 * story, exactly as the developer wrote it — or *changes* — not yet, here is
 * why (and, if they tried other values in Controls, which). Decisions are
 * never withdrawn: each one is kept, and the latest on a story, whoever made
 * it, is where that story stands for everyone (state()).
 *
 * It is made on staging, where the client tries the block, and an approval
 * has to reach the story file in git as `approval: { by, date, version }`. So
 * a review is a plain record that travels: stored in the reviewer's Craft
 * user preferences (no table, no migration), exported as a file, imported
 * where the story files are written.
 */
final class ClientReview
{
    public const APPROVED = 'approved';

    public const CHANGES = 'changes';

    public const DECISIONS = [self::APPROVED, self::CHANGES];

    public const MAX_COMMENT_LENGTH = 2000;

    /** Format of an exported file, checked on import. */
    public const EXPORT_FORMAT = 'component-guide/reviews';

    public const EXPORT_VERSION = 1;

    /** Where a story stands: see state(). */
    public const STATE_REVIEW = 'review';

    public const STATE_APPROVED = 'approved';

    public const STATE_CHANGES = 'changes';

    /** Why a story is back to review: never decided, or something happened since. */
    public const REASON_NEW = 'new';

    /** The block's template changed after it was approved. */
    public const REASON_CHANGED = 'changed';

    /** The block's template changed after changes were asked for — most likely made. */
    public const REASON_UPDATED = 'updated';

    /** The developer marked the request for changes as done (a fix outside the template). */
    public const REASON_FIXED = 'fixed';

    /**
     * The block as the reviewer saw it in one story: a short hash of the
     * template and of that story's args. Change either — the markup, or what
     * the story shows — and a decision on it is about something else now.
     * Line endings are evened out and keys sorted, so a checkout on Windows,
     * or a story file reformatted, is the same version as the one on staging.
     *
     * @param array<mixed> $storyArgs
     */
    public static function fingerprint(string $template, array $storyArgs = []): string
    {
        $template = rtrim(str_replace(["\r\n", "\r"], "\n", $template));

        return substr(sha1($template . "\n" . json_encode(self::sorted($storyArgs))), 0, 12);
    }

    /**
     * The latest decision on each story, whoever made it: “componentId|storyId”
     * => review. Later decisions replace earlier ones for the state; the
     * earlier ones stay as history.
     *
     * @param list<array<string, mixed>> $reviews
     * @return array<string, array<string, mixed>>
     */
    public static function latest(array $reviews): array
    {
        $latest = [];
        foreach ($reviews as $review) {
            $key = $review['componentId'] . '|' . $review['storyId'];
            if (!isset($latest[$key]) || self::newer($review, $latest[$key])) {
                $latest[$key] = $review;
            }
        }

        return $latest;
    }

    /**
     * Where a story stands, for everyone: one of three states.
     *
     * - The latest decision on it decides: approved, or changes requested.
     * - A decision made on an earlier version of the block's template is back
     *   to review: an approval was of a block that looks different now
     *   (`changed`); a request for changes has most likely been answered
     *   (`updated`) — or the developer said so (`fixed`).
     * - With no decision on this site, the story file's `approval:` decides —
     *   which is how production, where nobody reviews, knows.
     * - Otherwise: to review (`new`).
     *
     * A decision or approval without a version (or a template that cannot be
     * read) counts as current.
     *
     * @param array<string, mixed>|null $latest The latest decision on the story here.
     * @param array{by: string, date: string, version?: string|null}|null $approval The story file's.
     * @return array{state: string, reason: string|null, review: array<string, mixed>|null, by: string|null, date: string|null}
     */
    public static function state(?array $latest, ?array $approval, ?string $currentVersion): array
    {
        if ($latest !== null) {
            $stale = self::stale($latest['version'] ?? null, $currentVersion);
            [$state, $reason] = match (true) {
                $latest['decision'] === self::APPROVED && $stale => [self::STATE_REVIEW, self::REASON_CHANGED],
                $latest['decision'] === self::APPROVED => [self::STATE_APPROVED, null],
                $stale => [self::STATE_REVIEW, self::REASON_UPDATED],
                is_array($latest['fixed'] ?? null) => [self::STATE_REVIEW, self::REASON_FIXED],
                default => [self::STATE_CHANGES, null],
            };

            return ['state' => $state, 'reason' => $reason, 'review' => $latest, 'by' => $latest['by'], 'date' => $latest['date']];
        }

        if ($approval !== null) {
            $stale = self::stale($approval['version'] ?? null, $currentVersion);

            return [
                'state' => $stale ? self::STATE_REVIEW : self::STATE_APPROVED,
                'reason' => $stale ? self::REASON_CHANGED : null,
                'review' => null,
                'by' => $approval['by'],
                'date' => $approval['date'],
            ];
        }

        return ['state' => self::STATE_REVIEW, 'reason' => self::REASON_NEW, 'review' => null, 'by' => null, 'date' => null];
    }

    /**
     * A block's state from its stories': a request for changes on any of them
     * is work; the block is approved only when every story is — one approved
     * story of three leaves two to look at; otherwise it is to review.
     *
     * @param list<string> $storyStates
     */
    public static function blockState(array $storyStates): string
    {
        return match (true) {
            in_array(self::STATE_CHANGES, $storyStates, true) => self::STATE_CHANGES,
            $storyStates !== [] && !in_array(self::STATE_REVIEW, $storyStates, true) => self::STATE_APPROVED,
            default => self::STATE_REVIEW,
        };
    }

    private static function stale(mixed $version, ?string $current): bool
    {
        return is_string($version) && $version !== '' && $current !== null && $version !== $current;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function newer(array $a, array $b): bool
    {
        return self::newestFirst($a, $b) < 0;
    }

    /**
     * For usort: the newest decision first. By the moment, not by the text of
     * `dateCreated`: its offset changes with daylight saving time and with the
     * site's time zone, so 01:47-07:00 is earlier than 01:37-08:00.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function newestFirst(array $a, array $b): int
    {
        $ta = strtotime((string)($a['dateCreated'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['dateCreated'] ?? '')) ?: 0;

        return $ta !== $tb ? $tb <=> $ta : strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
    }

    /**
     * A comment fit to keep: trimmed, at most MAX_COMMENT_LENGTH, line breaks
     * kept — a list of what to change is often a list.
     */
    public static function comment(string $comment): string
    {
        $comment = str_replace(["\r\n", "\r"], "\n", $comment);
        $comment = trim((string)preg_replace("/\n{3,}/", "\n\n", $comment));

        return mb_substr($comment, 0, self::MAX_COMMENT_LENGTH);
    }

    /**
     * A new review, as stored.
     *
     * @param array<string, mixed> $args The values on screen — for an approval, the story's own.
     * @param string|null $version The block's template as decided on (fingerprint()); null when it cannot be read.
     * @return array{id: string, componentId: string, storyId: string, storyTitle: string, decision: string, args: array<string, mixed>, comment: string, by: string, username: string|null, date: string, dateCreated: string, version: string|null, fixed: array{by: string, date: string}|null}
     */
    public static function record(
        string $componentId,
        string $storyId,
        string $storyTitle,
        string $decision,
        array $args,
        string $comment,
        string $by,
        \DateTimeInterface $now,
        ?string $version = null,
        ?string $username = null,
    ): array {
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Unknown decision.');
        }

        $comment = self::comment($comment);
        if ($decision === self::CHANGES && $comment === '') {
            throw new \InvalidArgumentException('Say what should change.');
        }

        return [
            'id' => bin2hex(random_bytes(6)),
            'componentId' => $componentId,
            'storyId' => $storyId,
            'storyTitle' => $storyTitle,
            'decision' => $decision,
            'args' => $args,
            'comment' => $comment,
            'by' => self::name($by),
            'username' => self::username($username),
            'date' => $now->format('Y-m-d'),
            'dateCreated' => $now->format(DATE_ATOM),
            'version' => $version,
            'fixed' => null,
        ];
    }

    /**
     * A stored or imported record, or null when it is not one. Preferences and
     * export files are plain JSON anyone can edit; nothing in them is trusted
     * further than this.
     *
     * @return array{id: string, componentId: string, storyId: string, storyTitle: string, decision: string, args: array<string, mixed>, comment: string, by: string, username: string|null, date: string, dateCreated: string, version: string|null, fixed: array{by: string, date: string}|null}|null
     */
    public static function normalize(mixed $record): ?array
    {
        if (!is_array($record)) {
            return null;
        }

        foreach (['id', 'componentId', 'storyId', 'storyTitle', 'decision', 'by', 'date'] as $key) {
            if (!is_string($record[$key] ?? null) || $record[$key] === '') {
                return null;
            }
        }
        if (!in_array($record['decision'], self::DECISIONS, true)
            || !is_array($record['args'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['date']) !== 1
            || preg_match('/^[\w-]+$/', $record['componentId']) !== 1
        ) {
            return null;
        }

        return [
            'id' => $record['id'],
            'componentId' => $record['componentId'],
            'storyId' => $record['storyId'],
            'storyTitle' => $record['storyTitle'],
            'decision' => $record['decision'],
            'args' => $record['args'],
            'comment' => is_string($record['comment'] ?? null) ? self::comment($record['comment']) : '',
            'by' => self::name($record['by']),
            'username' => self::username($record['username'] ?? null),
            'date' => $record['date'],
            'dateCreated' => is_string($record['dateCreated'] ?? null) ? $record['dateCreated'] : $record['date'],
            'version' => is_string($record['version'] ?? null) && preg_match('/^[a-f0-9]{8,40}$/', $record['version']) === 1
                ? $record['version']
                : null,
            'fixed' => $record['decision'] === self::CHANGES ? self::fixed($record['fixed'] ?? null) : null,
        ];
    }

    /**
     * A developer's “fixed” mark on a request for changes.
     *
     * @return array{by: string, date: string}|null
     */
    public static function fixed(mixed $mark): ?array
    {
        if (!is_array($mark)
            || !is_string($mark['by'] ?? null)
            || !is_string($mark['date'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $mark['date']) !== 1
        ) {
            return null;
        }

        return ['by' => self::name($mark['by']), 'date' => $mark['date']];
    }

    /**
     * Whether two sets of story args are the same values — key order aside,
     * which a trip through JSON and back does not keep for certain.
     *
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    public static function sameArgs(array $a, array $b): bool
    {
        return json_encode(self::sorted($a)) === json_encode(self::sorted($b));
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(static fn(mixed $v): mixed => is_array($v) ? self::sorted($v) : $v, $value);
    }

    /**
     * Reviews as one file for the trip from staging to where the story files
     * are written. With where they come from — the site's host and Craft's
     * environment (CRAFT_ENVIRONMENT) — so the import can say where to look
     * again.
     *
     * @param list<array<string, mixed>> $reviews
     */
    public static function export(array $reviews, string $site, \DateTimeInterface $now, ?string $environment = null): string
    {
        return (string)json_encode([
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'site' => $site,
            'environment' => $environment,
            'exported' => $now->format(DATE_ATOM),
            'reviews' => array_values($reviews),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The reviews in an exported file. Anything that is not one is refused as
     * a whole; a review that does not hold up is left out, and counted.
     *
     * @return array{reviews: list<array<string, mixed>>, skipped: int, site: string|null, environment: string|null, exported: string|null}
     * @throws \InvalidArgumentException
     */
    public static function import(string $json): array
    {
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('This is not a reviews file: it is not valid JSON.');
        }

        if (!is_array($data) || ($data['format'] ?? null) !== self::EXPORT_FORMAT || !is_array($data['reviews'] ?? null)) {
            throw new \InvalidArgumentException('This is not a Component Guide reviews file.');
        }
        if (($data['version'] ?? null) !== self::EXPORT_VERSION) {
            throw new \InvalidArgumentException('This reviews file was written by a newer version of Component Guide.');
        }

        $reviews = [];
        $skipped = 0;
        foreach ($data['reviews'] as $record) {
            $review = self::normalize($record);
            if ($review === null) {
                $skipped++;
                continue;
            }
            $reviews[] = $review;
        }

        // Where the file comes from, for the import to say; a file from an
        // earlier version has no environment.
        $text = static fn(mixed $v): ?string => is_string($v) && $v !== '' && mb_strlen($v) <= 200 ? $v : null;

        return [
            'reviews' => $reviews,
            'skipped' => $skipped,
            'site' => $text($data['site'] ?? null),
            'environment' => $text($data['environment'] ?? null),
            'exported' => $text($data['exported'] ?? null),
        ];
    }

    /**
     * A Craft username as kept with a decision and written into the story
     * file's `approval:` — the user can always be found again by it.
     */
    private static function username(mixed $username): ?string
    {
        return is_string($username) && preg_match('/^[^\s\'"\\\\]{1,100}$/u', $username) === 1 ? $username : null;
    }

    private static function name(string $name): string
    {
        $name = trim((string)preg_replace('/\s+/u', ' ', $name));

        return $name !== '' ? mb_substr($name, 0, 120) : 'unknown';
    }
}

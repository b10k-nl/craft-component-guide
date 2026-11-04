<?php

namespace b10k\componentguide\services;

use b10k\componentguide\helpers\ClientReview;
use b10k\componentguide\Plugin;
use Craft;
use craft\elements\User;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Client review: approve a story as written, or ask for changes.
 *
 * Decisions are kept in each reviewer's Craft user preferences, as editors'
 * own variants are (see UserStories): the plugin still ships no table and no
 * migration. Unlike variants, decisions are read across users — that is their
 * purpose: everyone with access sees where each story stands. Only users who
 * may review are read, which on a staging site is a handful of accounts.
 *
 * Decisions are never withdrawn. A new one is added, and the latest on a
 * story, whoever made it, is that story's state (ClientReview::state()); the
 * earlier ones stay as its history.
 *
 * Off unless the plugin settings turn it on (Plugin::clientReviewEnabled()).
 */
class ClientReviews extends Component
{
    public const PREFERENCE_KEY = 'componentGuide.reviews';

    /** When this admin last exported the decisions — to count what is new since. */
    public const EXPORTED_KEY = 'componentGuide.reviewsExported';

    /** Decisions kept per reviewer; past this the oldest go. Keeps the preferences small. */
    public const MAX_PER_USER = 200;

    /** How long after deciding its author can still undo it — a slip of the mouse, not a change of mind. */
    public const UNDO_SECONDS = 30;

    /** Reviewers read at most — client accounts on a staging site, not a user base. */
    private const MAX_REVIEWERS = 200;

    /** @var array<string, string|false> component id => template source (false: unreadable), per request */
    private array $versions = [];

    /** @var list<array<string, mixed>>|null all(), per request */
    private ?array $all = null;

    /** @var array<string, array{state: string, stories: array<string, array<string, mixed>>}>|null states(), per request */
    private ?array $states = null;

    /**
     * Every decision by every user who may review, newest first, each with the
     * reviewer's user id.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        // A page asks several times; each ask would read every reviewer's
        // preferences. Once per request, forgotten on every write.
        if ($this->all !== null) {
            return $this->all;
        }

        $ids = $this->reviewerIds();
        // Named as the user is called now — full name, or username without
        // one — not as they were when they decided: a name set later shows.
        $users = [];
        foreach ($ids === [] ? [] : User::find()->id($ids)->status(null)->all() as $user) {
            $users[(int)$user->id] = $user;
        }

        $reviews = [];
        foreach ($ids as $userId) {
            foreach ($this->stored($userId) as $review) {
                $review['userId'] = $userId;
                if (isset($users[$userId])) {
                    $review['by'] = $this->nameOf($users[$userId]);
                    $review['username'] = (string)$users[$userId]->username;
                }
                $reviews[] = $review;
            }
        }

        usort($reviews, [ClientReview::class, 'newestFirst']);

        return $this->all = $reviews;
    }

    /**
     * Where every story of every component stands, and each component from its
     * stories: component id => { state, stories: story id => ClientReview::state() }.
     *
     * @return array<string, array{state: string, stories: array<string, array<string, mixed>>}>
     */
    public function states(): array
    {
        if ($this->states !== null) {
            return $this->states;
        }

        $latest = ClientReview::latest($this->all());
        $states = [];
        $names = $this->namesOfApprovers();

        foreach (Plugin::getInstance()->getRepository()->getAll() as $component) {
            if ($component->stories === []) {
                continue;
            }
            $stories = [];
            foreach ($component->stories as $story) {
                $state = ClientReview::state(
                    $latest[$component->id . '|' . $story->id] ?? null,
                    $story->approval,
                    $this->versionOf($component->id, $story->id),
                );
                // The story file names the approver by username: shown as
                // they are called now, when they are a user here.
                if ($state['review'] === null && $state['by'] !== null) {
                    $state['by'] = $names[$state['by']] ?? $state['by'];
                }
                $stories[$story->id] = $state;
            }
            $states[$component->id] = [
                'state' => ClientReview::blockState(array_values(array_map(static fn(array $s): string => $s['state'], $stories))),
                'stories' => $stories,
            ];
        }

        return $this->states = $states;
    }

    /**
     * A story's decisions, newest first — its history.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $componentId, string $storyId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $r): bool => $r['componentId'] === $componentId && $r['storyId'] === $storyId,
        ));
    }

    /**
     * Records the signed-in user's decision on a story. It is added, never
     * replaces: the latest decision is the story's state, and the earlier
     * ones are what was decided before.
     *
     * @param array<string, mixed> $args The values on screen; for an approval, the story's own.
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public function decide(string $componentId, string $storyId, string $storyTitle, string $decision, array $args, string $comment): array
    {
        $user = $this->user();
        if ($user === null) {
            throw new InvalidArgumentException('Not signed in.');
        }

        try {
            $review = ClientReview::record(
                $componentId,
                $storyId,
                $storyTitle,
                $decision,
                $args,
                $comment,
                $this->nameOf($user),
                new \DateTimeImmutable(),
                $this->versionOf($componentId, $storyId),
                (string)$user->username,
            );
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $stored = $this->stored((int)$user->id);
        $stored[] = $review;
        // The oldest go first: they are the most likely to be superseded.
        $this->store($user, array_slice($stored, -self::MAX_PER_USER));

        return $review;
    }

    /**
     * Takes back the signed-in user's own decision in the seconds after the
     * click (UNDO_SECONDS) — as if it had not been clicked. After that it
     * stays: decisions are not withdrawn, the next one replaces it.
     */
    public function undo(string $id): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $stored = $this->stored((int)$user->id);
        foreach ($stored as $i => $review) {
            if ($review['id'] !== $id) {
                continue;
            }
            $age = time() - (strtotime((string)$review['dateCreated']) ?: 0);
            if ($age < 0 || $age > self::UNDO_SECONDS) {
                return false;
            }
            unset($stored[$i]);
            $this->store($user, array_values($stored));

            return true;
        }

        return false;
    }

    /**
     * Deletes every decision on one component — or on all of them — by
     * everyone: the developer clearing their own trial runs before the client
     * starts. The controller checks the clear permission and the setting; the
     * command runs for whoever can run commands. Logged
     * with who and how many. Approvals already in the story files stay: they
     * are code.
     *
     * @return int How many decisions were deleted.
     */
    public function clear(?string $componentId = null, string $by = 'console'): int
    {
        $removed = 0;
        foreach ($this->reviewerIds() as $userId) {
            $stored = $this->stored($userId);
            $kept = array_values(array_filter(
                $stored,
                static fn(array $r): bool => $componentId !== null && $r['componentId'] !== $componentId,
            ));
            if (count($kept) === count($stored)) {
                continue;
            }
            $author = Craft::$app->getUsers()->getUserById($userId);
            if ($author === null) {
                continue;
            }
            $removed += count($stored) - count($kept);
            $this->store($author, $kept);
        }

        // A warning, not info: Craft keeps only warnings and errors in its
        // logs by default, and this one is the trail of who deleted what.
        Craft::warning(sprintf(
            'Client review: %s cleared %d decision(s) %s.',
            $by,
            $removed,
            $componentId !== null ? "on “{$componentId}”" : 'on every component',
        ), 'component-guide');

        return $removed;
    }

    /**
     * The developer's mark on a request for changes: done, back to review.
     * For a fix the template fingerprint cannot see — a partial it includes,
     * the CSS. Kept on the request itself, in its author's preferences.
     * `$fixed = false` takes the mark back.
     */
    public function markFixed(string $id, bool $fixed = true): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        foreach ($this->reviewerIds() as $userId) {
            $stored = $this->stored($userId);
            foreach ($stored as $i => $review) {
                if ($review['id'] !== $id) {
                    continue;
                }
                if ($review['decision'] !== ClientReview::CHANGES) {
                    return false;
                }
                $author = Craft::$app->getUsers()->getUserById($userId);
                if ($author === null) {
                    return false;
                }
                $stored[$i]['fixed'] = $fixed
                    ? ['by' => $this->nameOf($user), 'date' => (new \DateTimeImmutable())->format('Y-m-d')]
                    : null;
                $this->store($author, $stored);

                return true;
            }
        }

        return false;
    }

    /**
     * Decisions made since the signed-in user last exported — what a new
     * export would bring. All of them before the first export.
     */
    public function newSinceExport(): int
    {
        $since = $this->exportedOn()?->getTimestamp();

        return count(array_filter(
            $this->all(),
            static fn(array $r): bool => $since === null || (strtotime((string)$r['dateCreated']) ?: 0) > $since,
        ));
    }

    /**
     * When the signed-in user last exported, or null if never.
     */
    public function exportedOn(): ?\DateTimeImmutable
    {
        $user = $this->user();
        $on = $user !== null ? Craft::$app->getUsers()->getUserPreference((int)$user->id, self::EXPORTED_KEY) : null;
        if (!is_string($on)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($on);
        } catch (\Exception) {
            return null;
        }
    }

    public function markExported(\DateTimeInterface $now): void
    {
        $user = $this->user();
        if ($user !== null) {
            Craft::$app->getUsers()->saveUserPreferences($user, [self::EXPORTED_KEY => $now->format(DATE_ATOM)]);
        }
    }

    /**
     * A story's version as it is now — its block's template and its own args
     * (ClientReview::fingerprint()) — or null when there is no such story or
     * the template cannot be read.
     */
    public function versionOf(string $componentId, string $storyId): ?string
    {
        $component = Plugin::getInstance()->getRepository()->getById($componentId);
        $story = $component?->getStory($storyId);
        if ($component === null || $story === null) {
            return null;
        }
        if (!array_key_exists($componentId, $this->versions)) {
            $path = $component->absoluteTemplatePath;
            $this->versions[$componentId] = is_file($path) ? @file_get_contents($path) : false;
        }
        $source = $this->versions[$componentId];

        return is_string($source) ? ClientReview::fingerprint($source, $story->args) : null;
    }

    public function canReview(): bool
    {
        return Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_REVIEW);
    }

    /**
     * @return list<int>
     */
    private function reviewerIds(): array
    {
        $ids = User::find()
            ->can(Plugin::PERMISSION_REVIEW)
            ->status(null)
            ->limit(self::MAX_REVIEWERS)
            ->ids();

        return array_map('intval', $ids);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stored(int $userId): array
    {
        $stored = Craft::$app->getUsers()->getUserPreference($userId, self::PREFERENCE_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        $reviews = [];
        foreach ($stored as $record) {
            $review = ClientReview::normalize($record);
            if ($review !== null) {
                $reviews[] = $review;
            }
        }

        return $reviews;
    }

    /**
     * Username => display name, for every approver named in a story file.
     *
     * @return array<string, string>
     */
    private function namesOfApprovers(): array
    {
        $usernames = [];
        foreach (Plugin::getInstance()->getRepository()->getAll() as $component) {
            foreach ($component->stories as $story) {
                if ($story->approval !== null) {
                    $usernames[$story->approval['by']] = true;
                }
            }
        }
        if ($usernames === []) {
            return [];
        }

        $names = [];
        foreach (User::find()->username(array_keys($usernames))->status(null)->all() as $user) {
            $names[(string)$user->username] = $this->nameOf($user);
        }

        return $names;
    }

    /**
     * Full name, or the username when there is none.
     */
    private function nameOf(User $user): string
    {
        return trim((string)$user->fullName) !== '' ? (string)$user->fullName : (string)$user->username;
    }

    private function user(): ?User
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param list<array<string, mixed>> $reviews
     */
    private function store(User $user, array $reviews): void
    {
        // Merged by top-level key: only our entry is replaced.
        Craft::$app->getUsers()->saveUserPreferences($user, [self::PREFERENCE_KEY => array_values($reviews)]);
        $this->all = null;
        $this->states = null;
    }
}

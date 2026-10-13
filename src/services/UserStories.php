<?php

namespace b10k\componentguide\services;

use b10k\componentguide\helpers\UserStoryBuilder;
use b10k\componentguide\models\AdapterBinding;
use b10k\componentguide\models\ComponentDefinition;
use b10k\componentguide\models\StoryDefinition;
use Craft;
use craft\elements\User;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Editors' own variants of a block, kept in their user preferences.
 *
 * Craft already stores a JSON blob of preferences per user: it is personal,
 * survives sessions and devices, and goes when the user goes. Using it means
 * the plugin still ships no migration and no table — "nothing to roll back" on
 * the plugin page stays true, in Lite as well as Pro. Cookies were ruled out
 * for the same feature in page presets: they are lost on a clear-out, tied to
 * one browser, and cannot be handed to anyone.
 *
 * Nothing here is ever read for anyone but the signed-in user. A variant
 * reaches other people only when its author shares it on purpose (see
 * UserStoryBuilder::snippet()).
 */
class UserStories extends Component
{
    public const PREFERENCE_KEY = 'componentGuide.stories';

    /** Enough for real use, small enough that the preferences blob stays small. */
    public const MAX_PER_USER = 100;

    /**
     * Every variant of the signed-in user, oldest first.
     *
     * @return list<array{id: string, componentId: string, baseStoryId: string, title: string, values: array<string, mixed>, dateCreated: string}>
     */
    public function all(): array
    {
        $user = $this->user();
        if ($user === null) {
            return [];
        }

        $stored = Craft::$app->getUsers()->getUserPreference($user->id, self::PREFERENCE_KEY, []);
        if (!is_array($stored)) {
            return [];
        }

        // Preferences are a plain JSON blob anyone with database access can
        // edit; a malformed entry is skipped, never trusted.
        $records = [];
        foreach ($stored as $record) {
            if (is_array($record)
                && is_string($record['id'] ?? null)
                && is_string($record['componentId'] ?? null)
                && is_string($record['baseStoryId'] ?? null)
                && is_string($record['title'] ?? null)
                && is_array($record['values'] ?? null)
            ) {
                $records[] = [
                    'id' => $record['id'],
                    'componentId' => $record['componentId'],
                    'baseStoryId' => $record['baseStoryId'],
                    'title' => $record['title'],
                    'values' => $record['values'],
                    'dateCreated' => is_string($record['dateCreated'] ?? null) ? $record['dateCreated'] : '',
                ];
            }
        }

        return $records;
    }

    /**
     * @return list<array{id: string, componentId: string, baseStoryId: string, title: string, values: array<string, mixed>, dateCreated: string}>
     */
    public function forComponent(string $componentId): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(array $record): bool => $record['componentId'] === $componentId,
        ));
    }

    /**
     * @return array{id: string, componentId: string, baseStoryId: string, title: string, values: array<string, mixed>, dateCreated: string}|null
     */
    public function find(string $id): ?array
    {
        foreach ($this->all() as $record) {
            if ($record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values Already cleaned by UserStoryBuilder::cleanValues().
     * @return array{id: string, componentId: string, baseStoryId: string, title: string, values: array<string, mixed>, dateCreated: string}
     * @throws InvalidArgumentException when the user is at the limit or signed out
     */
    public function add(string $componentId, string $baseStoryId, string $title, array $values): array
    {
        $user = $this->user();
        if ($user === null) {
            throw new InvalidArgumentException('Not signed in.');
        }

        $records = $this->all();
        if (count($records) >= self::MAX_PER_USER) {
            throw new InvalidArgumentException(Craft::t(
                'component-guide',
                'You have {count} saved variants, the most there can be. Delete one in the blocks gallery first.',
                ['count' => self::MAX_PER_USER],
            ));
        }

        $record = [
            'id' => bin2hex(random_bytes(6)),
            'componentId' => $componentId,
            'baseStoryId' => $baseStoryId,
            'title' => $title,
            'values' => $values,
            'dateCreated' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $records[] = $record;
        $this->store($user, $records);

        return $record;
    }

    public function delete(string $id): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        $records = $this->all();
        $kept = array_values(array_filter($records, static fn(array $r): bool => $r['id'] !== $id));
        if (count($kept) === count($records)) {
            return false;
        }

        $this->store($user, $kept);

        return true;
    }

    /**
     * A variant as a story the preview renderer can take.
     *
     * Its id is the base story's on purpose: placeholder tokens (`@image…`) are
     * seeded by component and story, so a variant shows the same picture as
     * the story it came from rather than a different random one.
     *
     * @param array{baseStoryId: string, title: string, values: array<string, mixed>} $record
     */
    public function story(ComponentDefinition $component, array $record, ?AdapterBinding $binding): ?StoryDefinition
    {
        $base = $component->getStory($record['baseStoryId']) ?? ($component->stories[0] ?? null);
        if ($base === null) {
            return null;
        }

        $editable = $binding !== null ? UserStoryBuilder::editableFields($binding, $component->stories) : [];

        return new StoryDefinition(
            id: $base->id,
            name: $record['title'],
            title: $record['title'],
            args: UserStoryBuilder::args($base->args, $record['values'], $editable),
            description: null,
            background: $base->background,
            viewport: $base->viewport,
        );
    }

    private function user(): ?User
    {
        $user = Craft::$app->getUser()->getIdentity();

        return $user instanceof User ? $user : null;
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function store(User $user, array $records): void
    {
        // saveUserPreferences() merges by top-level key, so this replaces only
        // our own entry and leaves the rest of the user's preferences alone.
        Craft::$app->getUsers()->saveUserPreferences($user, [self::PREFERENCE_KEY => array_values($records)]);
    }
}

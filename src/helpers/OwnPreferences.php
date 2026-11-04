<?php

namespace b10k\componentguide\helpers;

/**
 * What the plugin keeps in Craft's user preferences — editors' own variants,
 * clients' reviews, an admin's last export — all under one prefix, so it can
 * all be found and taken away again.
 *
 * User preferences are the one place the plugin writes to the database: no
 * table of its own, no migration, and Craft drops a user's preferences with
 * the user. Craft does not know which keys are ours, though, so uninstalling
 * the plugin would leave them behind unless we remove them (Plugin::afterUninstall()).
 */
final class OwnPreferences
{
    public const PREFIX = 'componentGuide.';

    /**
     * A user's preferences without ours, and whether anything was removed.
     *
     * @param array<string, mixed> $preferences
     * @return array{array<string, mixed>, bool}
     */
    public static function strip(array $preferences): array
    {
        $kept = array_filter(
            $preferences,
            static fn(string|int $key): bool => !str_starts_with((string)$key, self::PREFIX),
            ARRAY_FILTER_USE_KEY,
        );

        return [$kept, count($kept) !== count($preferences)];
    }
}

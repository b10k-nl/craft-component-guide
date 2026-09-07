<?php

namespace b10k\componentguide\services;

use yii\base\Component;

/**
 * Remembers which project files the control panel changed, so the guide can
 * say so out loud.
 *
 * The control panel has no `git status`. When it writes into `templates/` — a
 * scaffolded story, a status flipped from draft to stable — that change is
 * invisible until somebody happens to look at the working tree. The rule this
 * plugin holds itself to is not “never write from the CP”, it is “never write
 * *invisibly*”. This journal is what makes the writes visible: a banner on the
 * index lists them until they have been dealt with.
 *
 * It deliberately does not ask git anything. `exec` is often disabled, the web
 * container often has no git binary, and deployed environments (Craft Cloud)
 * have no `.git` at all. Instead the journal records what *it* wrote — path
 * and content hash — and verifies its own claim on every read.
 *
 * A warning that can be wrong must err towards silence, never towards noise:
 * a banner that lies twice is a banner nobody reads, and then the one true
 * warning is missed. So every entry is dropped the moment there is any doubt:
 *
 *   1. the file is gone, or its hash no longer matches what we wrote — someone
 *      edited or reverted it after us; our statement is no longer true;
 *   2. `.git/index` is newer than the file — a git operation happened after
 *      our write, almost certainly a commit or `git add`;
 *   3. a person clicked “reviewed”.
 *
 * Git remains the real safeguard. This is a courtesy, and it is phrased as one.
 */
class WriteJournal extends Component
{
    /** How far up from the templates directory to look for `.git/index`. */
    private const GIT_SEARCH_DEPTH = 5;

    /**
     * @param string $file Absolute path of the JSON journal (runtime storage,
     *        never inside the repository).
     * @param string|null $gitIndex Absolute path of `.git/index`, or null when
     *        unknown; {@see locateGitIndex()} finds it from a project path.
     */
    public function __construct(
        private readonly string $file,
        private ?string $gitIndex = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    /**
     * Records that the CP wrote $absolutePath and that its contents now hash to
     * $hash. Re-recording the same path replaces the earlier entry.
     *
     * @param 'scaffold'|'status' $action What kind of write it was.
     */
    public function record(string $absolutePath, string $hash, string $action): void
    {
        $entries = $this->load();
        $entries[$absolutePath] = [
            'hash' => $hash,
            'action' => $action,
            'time' => time(),
        ];
        $this->save($entries);
    }

    /**
     * Entries whose claim still holds, oldest first. Pruning happens here, on
     * read, so the banner is only ever built from verified facts.
     *
     * @return array<string, array{hash: string, action: string, time: int}>
     *         Keyed by absolute path.
     */
    public function entries(): array
    {
        $entries = $this->load();
        $kept = [];
        $gitIndexTime = $this->gitIndex !== null && is_file($this->gitIndex)
            ? (int)filemtime($this->gitIndex)
            : null;

        foreach ($entries as $path => $entry) {
            // 1. Gone or changed since we wrote it → our statement is stale.
            if (!is_file($path)) {
                continue;
            }
            $hash = @sha1_file($path);
            if ($hash === false || $hash !== ($entry['hash'] ?? null)) {
                continue;
            }

            // 2. A git operation after our write → very likely committed.
            if ($gitIndexTime !== null && $gitIndexTime > (int)filemtime($path)) {
                continue;
            }

            // Normalised here, once: the file on disk is arbitrary JSON
            // (hand-edited, half-written), so the strict shape this method
            // promises has to be produced rather than assumed.
            $action = $entry['action'] ?? null;
            $time = $entry['time'] ?? null;
            $kept[$path] = [
                'hash' => $hash,
                'action' => is_string($action) ? $action : '',
                'time' => is_int($time) ? $time : 0,
            ];
        }

        if (count($kept) !== count($entries)) {
            $this->save($kept);
        }

        uasort($kept, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);

        return $kept;
    }

    /**
     * Forgets one entry, or all of them when $absolutePath is null.
     */
    public function markReviewed(?string $absolutePath = null): void
    {
        if ($absolutePath === null) {
            $this->save([]);
            return;
        }

        $entries = $this->load();
        unset($entries[$absolutePath]);
        $this->save($entries);
    }

    /**
     * Finds `.git/index` by walking up from a project path. Returns null when
     * there is no repository within reach — deployed builds, for instance.
     */
    public static function locateGitIndex(string $fromPath): ?string
    {
        $dir = rtrim($fromPath, '/\\');
        for ($i = 0; $i <= self::GIT_SEARCH_DEPTH; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . '.git' . DIRECTORY_SEPARATOR . 'index';
            if (is_file($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * The journal as it is on disk. Deliberately loose: this is a JSON file in
     * runtime storage, and nothing stops it being edited, truncated or written
     * by an older version of the plugin. {@see entries()} is what turns it
     * into the strict shape callers rely on.
     *
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $raw = @file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    private function save(array $entries): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // Runtime storage is unwritable: the journal simply cannot keep
            // notes. Failing loudly here would block the write it describes.
            return;
        }
        @file_put_contents(
            $this->file,
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}

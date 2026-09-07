<?php

namespace b10k\componentguide\services;

use yii\base\Component;

/**
 * Changes the `status` of an existing story file — and nothing else in it.
 *
 * The edit is surgical on purpose. A story file is project code a developer
 * wrote (or reviewed): comments, spacing and argument order are theirs.
 * Re-serialising the whole file to flip one word would silently destroy that,
 * which is exactly the “second, worse copy” failure this plugin exists to
 * prevent. So the writer locates the `meta` block by its unambiguous
 * landmarks, touches the one `status` entry inside it, and leaves every other
 * byte as it found it.
 *
 * Handles both story languages:
 *
 *   Twig  {% set meta = { …, status: 'draft', … } %}
 *   PHP   'meta' => [ …, 'status' => 'draft', … ],
 *
 * A `status` key that lives inside `stories` (a component argument that
 * happens to be called `status`) is never touched, because the search is
 * confined to the meta region.
 */
class StoryStatusWriter extends Component
{
    /**
     * Sets the story file's status and returns the SHA-1 of the file as
     * written, so a caller can later verify the change is still on disk.
     *
     * @param string $absolutePath Story file, `.stories.twig` or `.stories.php`.
     * @param string $status One of {@see StoryParser::STATUSES}.
     * @return string SHA-1 of the new file contents.
     * @throws \RuntimeException When the file is missing, unreadable, has no
     * `meta` block, is not writable, or the status is not canonical.
     */
    public function setStatus(string $absolutePath, string $status): string
    {
        if (!in_array($status, StoryParser::STATUSES, true)) {
            throw new \RuntimeException(sprintf('“%s” is not a story status.', $status));
        }

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new \RuntimeException('The story file could not be read.');
        }

        $source = file_get_contents($absolutePath);
        if ($source === false) {
            throw new \RuntimeException('The story file could not be read.');
        }

        $updated = $this->rewrite($source, $status, str_ends_with($absolutePath, '.twig'));

        // Same two gates as the scaffolder, same honest error: a read-only
        // templates directory is a hosting fact, not a permissions bug.
        if (!is_writable($absolutePath) || !is_writable(dirname($absolutePath))) {
            throw new \RuntimeException(sprintf(
                'The story file is read-only on this environment (%s). Change the status locally and commit.',
                basename($absolutePath),
            ));
        }

        if (@file_put_contents($absolutePath, $updated, LOCK_EX) === false) {
            $error = error_get_last()['message'] ?? null;
            throw new \RuntimeException(sprintf(
                'Could not write %s.%s',
                basename($absolutePath),
                $error !== null ? ' ' . $error : '',
            ));
        }

        return sha1($updated);
    }

    /**
     * Pure transformation, exposed for tests: returns the source with the meta
     * status set, and everything else byte-for-byte identical.
     *
     * @throws \RuntimeException When no `meta` block can be found.
     */
    public function rewrite(string $source, string $status, bool $isTwig): string
    {
        [$start, $end] = $isTwig ? $this->twigMetaRegion($source) : $this->phpMetaRegion($source);

        $region = substr($source, $start, $end - $start);

        $pattern = $isTwig
            ? '/(\bstatus\s*:\s*)([\'"])[^\'"]*\2/'
            : '/([\'"]status[\'"]\s*=>\s*)([\'"])[^\'"]*\2/';

        $count = 0;
        $newRegion = preg_replace_callback(
            $pattern,
            static fn(array $m): string => $m[1] . $m[2] . $status . $m[2],
            $region,
            1,
            $count,
        );

        if ($newRegion === null) {
            throw new \RuntimeException('Could not parse the story file’s meta block.');
        }

        if ($count === 0) {
            // No status yet: add one as the first entry, in the file's own
            // indentation and quote style, so the result looks hand-written.
            $newRegion = $this->insertStatus($region, $status, $isTwig);
        }

        return substr($source, 0, $start) . $newRegion . substr($source, $end);
    }

    /**
     * The Twig meta block runs from `{% set meta` to the `%}` that closes that
     * tag. `%}` cannot appear inside a meta value in practice, which makes it a
     * safer terminator than the first `}` — descriptions may contain braces.
     *
     * @return array{int, int} Byte offsets [start, end) of the region.
     */
    private function twigMetaRegion(string $source): array
    {
        if (preg_match('/\{%-?\s*set\s+meta\s*=\s*\{/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException('The story file has no `{% set meta = { … } %}` block.');
        }

        $start = $m[0][1] + strlen($m[0][0]);
        $end = strpos($source, '%}', $start);

        if ($end === false) {
            throw new \RuntimeException('The story file’s meta block is not closed.');
        }

        return [$start, $end];
    }

    /**
     * The PHP meta block runs from `'meta' => [` to the `'stories'` key that
     * always follows it in the rich format. The simple format has no meta and
     * therefore no status to change.
     *
     * @return array{int, int}
     */
    private function phpMetaRegion(string $source): array
    {
        if (preg_match('/[\'"]meta[\'"]\s*=>\s*\[/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException('The story file has no `\'meta\' => [ … ]` block — only the rich PHP format carries a status.');
        }

        $start = $m[0][1] + strlen($m[0][0]);

        if (preg_match('/[\'"]stories[\'"]\s*=>/', $source, $s, PREG_OFFSET_CAPTURE, $start) !== 1) {
            throw new \RuntimeException('The story file’s meta block is not followed by a `stories` key.');
        }

        // Back up to the `]` that closes meta, so the region is exactly the
        // block's inside.
        $close = strrpos(substr($source, $start, $s[0][1] - $start), ']');
        if ($close === false) {
            throw new \RuntimeException('The story file’s meta block is not closed.');
        }

        return [$start, $start + $close];
    }

    /**
     * Inserts a status entry at the top of an existing meta region, copying the
     * indentation of the first entry already there (or four spaces).
     */
    private function insertStatus(string $region, string $status, bool $isTwig): string
    {
        $indent = preg_match('/\n([ \t]+)\S/', $region, $m) === 1 ? $m[1] : '    ';
        $entry = $isTwig
            ? sprintf("status: '%s',", $status)
            : sprintf("'status' => '%s',", $status);

        // Region starts right after the opening bracket. Keep a leading
        // newline if the block is multi-line; otherwise stay inline.
        if (str_starts_with(ltrim($region, " \t"), "\n")) {
            return "\n" . $indent . $entry . $region;
        }

        return ' ' . $entry . $region;
    }
}

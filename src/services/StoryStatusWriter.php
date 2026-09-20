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

        $this->writeAtomically($absolutePath, $updated);

        return sha1($updated);
    }

    /**
     * Replaces a file's contents without ever leaving it half-written.
     *
     * `file_put_contents()` truncates the file when it opens it, and the LOCK_EX
     * flag is applied only afterwards. A killed worker, a full disk or a quota
     * between those two moments leaves the developer's story file empty — and
     * this is a file we did not create and promised not to damage. Writing a
     * sibling temp file and renaming it over the original means the file is
     * either the old bytes or the new ones, never nothing.
     *
     * Short writes are caught too: `file_put_contents()` returns a byte count,
     * so checking only for `false` accepts a truncated result as success — and
     * the caller would then journal a SHA-1 for contents that are not on disk.
     *
     * @throws \RuntimeException
     */
    private function writeAtomically(string $absolutePath, string $contents): void
    {
        $temp = $absolutePath . '.cg-' . bin2hex(random_bytes(6)) . '.tmp';

        $written = @file_put_contents($temp, $contents);

        if ($written === false || $written !== strlen($contents)) {
            @unlink($temp);
            $error = error_get_last()['message'] ?? null;
            throw new \RuntimeException(sprintf(
                'Could not write %s.%s',
                basename($absolutePath),
                $error !== null ? ' ' . $error : '',
            ));
        }

        // The temp file is born with the default mask; the story file may have
        // been given wider or narrower permissions deliberately. Carry them
        // over, so a rename does not quietly change who can edit it.
        $mode = @fileperms($absolutePath);
        if ($mode !== false) {
            @chmod($temp, $mode & 0777);
        }

        if (!@rename($temp, $absolutePath)) {
            @unlink($temp);
            $error = error_get_last()['message'] ?? null;
            throw new \RuntimeException(sprintf(
                'Could not replace %s.%s',
                basename($absolutePath),
                $error !== null ? ' ' . $error : '',
            ));
        }
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

        $found = $this->findStatusValue($region, $isTwig);

        if ($found === null) {
            // No status yet: add one as the first entry, in the file's own
            // indentation and quote style, so the result looks hand-written.
            $newRegion = $this->insertStatus($region, $status, $isTwig);
        } else {
            [$valueStart, $valueEnd, $quote] = $found;
            $newRegion = substr($region, 0, $valueStart)
                . $quote . $status . $quote
                . substr($region, $valueEnd);
        }

        return substr($source, 0, $start) . $newRegion . substr($source, $end);
    }

    /**
     * Locates the `status` **key** inside a meta region and returns the bounds
     * of its quoted value.
     *
     * A regular expression cannot do this job, and the first version of this
     * class proved it in the worst way: it matched the first occurrence of
     * `status` anywhere in the region, so a meta description that merely
     * mentioned the word won the race. Clicking "mark stable" then rewrote the
     * developer's own sentence, left the real status untouched, and reported
     * success. Corrupting the file we promise never to corrupt, silently.
     *
     * So this walks the region instead, tracking string literals, comments and
     * nesting, and only accepts `status` when it sits at the top level of the
     * meta hash in key position. Anything inside a value, a nested structure or
     * a comment is invisible to it.
     *
     * @return array{int, int, string}|null [value start, value end, quote char]
     * as offsets into `$region`, or null when the key is absent.
     */
    private function findStatusValue(string $region, bool $isTwig): ?array
    {
        // Twig keys are bare identifiers; PHP keys are themselves quoted, so
        // the key has to be tried BEFORE a quote is treated as a string to skip
        // — otherwise every PHP key is swallowed as a literal and the scan
        // finds nothing.
        $key = $isTwig
            ? '/status\s*:\s*/A'
            : '/([\'"])status\1\s*=>\s*/A';

        $length = strlen($region);
        $depth = 0;
        $i = 0;

        while ($i < $length) {
            $char = $region[$i];

            // PHP story files may carry comments between entries.
            if (!$isTwig && ($char === '#' || ($char === '/' && ($region[$i + 1] ?? '') === '/'))) {
                $newline = strpos($region, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                continue;
            }

            if (!$isTwig && $char === '/' && ($region[$i + 1] ?? '') === '*') {
                $close = strpos($region, '*/', $i + 2);
                $i = $close === false ? $length : $close + 2;
                continue;
            }

            if ($char === '{' || $char === '[' || $char === '(') {
                $depth++;
                $i++;
                continue;
            }

            if ($char === '}' || $char === ']' || $char === ')') {
                $depth--;
                $i++;
                continue;
            }

            if ($depth === 0 && preg_match($key, $region, $m, 0, $i) === 1) {
                // A key is a fresh identifier, not the tail of a longer one:
                // `pageStatus:` and `block.status:` are not our key.
                $before = $i > 0 ? $region[$i - 1] : ',';

                if (preg_match('/[\w.]/', $before) !== 1) {
                    $valueStart = $i + strlen($m[0]);
                    $quote = $region[$valueStart] ?? '';

                    if ($quote !== "'" && $quote !== '"') {
                        // A non-literal value (a variable, a concatenation) is
                        // not ours to rewrite.
                        return null;
                    }

                    return [$valueStart, $this->skipString($region, $valueStart), $quote];
                }
            }

            // Only now is a quote a string literal to step over — this is what
            // keeps a description that mentions the word out of the way.
            if ($char === "'" || $char === '"') {
                $i = $this->skipString($region, $i);
                continue;
            }

            $i++;
        }

        return null;
    }

    /**
     * Index just past the string literal that starts at `$from`.
     */
    private function skipString(string $region, int $from): int
    {
        $quote = $region[$from];
        $length = strlen($region);
        $i = $from + 1;

        while ($i < $length) {
            if ($region[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($region[$i] === $quote) {
                return $i + 1;
            }
            $i++;
        }

        return $length;
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

<?php

namespace b10k\componentguide\helpers;

/**
 * Writing the developer's files without ever damaging them.
 *
 * Every file the guide writes into `templates/` is project code: a story file
 * the developer wrote, or one they will commit. Two ways to lose one are
 * closed here — a write cut off halfway, and two writers racing for the same
 * new file.
 */
final class AtomicFile
{
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
     * so checking only for `false` accepts a truncated result as success.
     *
     * @throws \RuntimeException
     */
    public static function replace(string $absolutePath, string $contents): void
    {
        $temp = $absolutePath . '.cg-' . bin2hex(random_bytes(6)) . '.tmp';

        $written = @file_put_contents($temp, $contents);

        if ($written === false || $written !== strlen($contents)) {
            @unlink($temp);
            throw new \RuntimeException(sprintf('Could not write %s.%s', basename($absolutePath), self::lastError()));
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
            throw new \RuntimeException(sprintf('Could not replace %s.%s', basename($absolutePath), self::lastError()));
        }
    }

    /**
     * Creates a new file, and only a new one.
     *
     * Checking `file_exists()` first and writing afterwards leaves a gap: two
     * scaffolds at once — the control panel and the console, two tabs — both
     * see no file, and the second overwrites the first. Opening with `x` makes
     * the check and the creation one step the filesystem guarantees.
     *
     * @return bool False when the file already exists; nothing is written then.
     * @throws \RuntimeException When the file cannot be created or fully written.
     */
    public static function create(string $absolutePath, string $contents): bool
    {
        $handle = @fopen($absolutePath, 'xb');
        if ($handle === false) {
            if (file_exists($absolutePath)) {
                return false;
            }
            throw new \RuntimeException(sprintf('Could not create %s.%s', basename($absolutePath), self::lastError()));
        }

        $written = @fwrite($handle, $contents);
        $flushed = @fflush($handle);
        @fclose($handle);

        if ($written !== strlen($contents) || !$flushed) {
            // Ours, and incomplete: better no file than half a story.
            @unlink($absolutePath);
            throw new \RuntimeException(sprintf('Could not write %s.%s', basename($absolutePath), self::lastError()));
        }

        return true;
    }

    private static function lastError(): string
    {
        $error = error_get_last()['message'] ?? null;

        return $error !== null ? ' ' . $error : '';
    }
}

<?php

namespace b10k\componentguide\helpers;

/**
 * Writes a client's approval into a Twig story file — the `approval:` key of
 * one story — and nothing else in the file changes.
 *
 * Like the status writer, it is surgical on purpose: a story file is project
 * code with the developer's comments, spacing and order. The stories hash and
 * the story are found by walking the source with strings and comments
 * skipped; a file this cannot read with certainty is left alone, and the
 * caller hands the line over to be added by hand instead.
 */
final class StoryAppender
{
    /**
     * Sets a story's `approval:` — added as its first key, or the one it has
     * replaced — and changes nothing else.
     *
     * @param array{by: string, date: string, version?: string|null} $approval
     * @throws \RuntimeException when the story cannot be found with certainty,
     *   or is written as bare args (no `args:` to sit beside)
     */
    public static function approve(string $source, string $title, array $approval): string
    {
        [$entryOpen, $entryClose] = self::story($source, $title);
        $keys = self::topLevelKeys($source, $entryOpen, $entryClose);
        $literal = UserStoryBuilder::approvalLiteral($approval);

        foreach ($keys as [$key, , $after]) {
            if ($key !== 'approval') {
                continue;
            }
            $valueOpen = self::skipBlank($source, $after);
            $valueClose = ($source[$valueOpen] ?? '') === '{' ? self::matchingBrace($source, $valueOpen) : null;
            if ($valueClose === null) {
                throw new \RuntimeException(sprintf('The approval of “%s” is not written as { … } here.', $title));
            }

            return substr($source, 0, $valueOpen) . $literal . substr($source, $valueClose + 1);
        }

        if (!in_array('args', array_map(static fn(array $k): string => $k[0], $keys), true)) {
            // Bare args: an `approval:` key would read as one more argument.
            throw new \RuntimeException(sprintf('Story “%s” is written without “args:”.', $title));
        }

        $line = 'approval: ' . $literal . ',';
        $first = $keys[0][1];
        $between = substr($source, $entryOpen + 1, $first - $entryOpen - 1);
        $newline = strrpos($between, "\n");

        if ($newline === false) {
            // `{ args: { … } }` on one line: stays on one line.
            return substr($source, 0, $first) . $line . ' ' . substr($source, $first);
        }

        $lineStart = $entryOpen + 1 + $newline + 1;
        $indent = substr($source, $lineStart, $first - $lineStart);

        return substr($source, 0, $lineStart) . $indent . $line . "\n" . substr($source, $lineStart);
    }

    /**
     * Takes a story's `approval:` out — for a story someone asked to change
     * after it was approved. Unchanged when it has none.
     *
     * @throws \RuntimeException when the story cannot be found with certainty
     */
    public static function removeApproval(string $source, string $title): string
    {
        [$entryOpen, $entryClose] = self::story($source, $title);

        foreach (self::topLevelKeys($source, $entryOpen, $entryClose) as [$key, $start, $after]) {
            if ($key !== 'approval') {
                continue;
            }
            $valueOpen = self::skipBlank($source, $after);
            $valueClose = ($source[$valueOpen] ?? '') === '{' ? self::matchingBrace($source, $valueOpen) : null;
            if ($valueClose === null) {
                throw new \RuntimeException(sprintf('The approval of “%s” is not written as { … } here.', $title));
            }

            $end = $valueClose + 1;
            if (($source[self::skipSpaces($source, $end)] ?? '') === ',') {
                $end = self::skipSpaces($source, $end) + 1;
            }
            $lineStart = strrpos(substr($source, 0, $start), "\n");
            $lineStart = $lineStart === false ? 0 : $lineStart + 1;
            $restEnd = self::skipSpaces($source, $end);
            if (($source[$restEnd] ?? '') === "\r" && ($source[$restEnd + 1] ?? '') === "\n") {
                $restEnd++;
            }

            // On a line of its own: the whole line goes.
            if (trim(substr($source, $lineStart, $start - $lineStart)) === '' && ($source[$restEnd] ?? "\n") === "\n") {
                return substr($source, 0, $lineStart) . substr($source, $restEnd + 1);
            }

            return substr($source, 0, $start) . substr($source, self::skipSpaces($source, $end));
        }

        return $source;
    }

    /**
     * Offsets of a story's own braces in the stories hash.
     *
     * @return array{int, int}
     * @throws \RuntimeException
     */
    private static function story(string $source, string $title): array
    {
        [$open, $close] = self::storiesHash($source);

        foreach (self::topLevelKeys($source, $open, $close) as [$key, , $after]) {
            if ($key !== $title) {
                continue;
            }
            $entryOpen = self::skipBlank($source, $after);
            if (($source[$entryOpen] ?? '') !== '{') {
                throw new \RuntimeException(sprintf('Story “%s” is not written as { … } here.', $title));
            }
            $entryClose = self::matchingBrace($source, $entryOpen);
            if ($entryClose === null) {
                throw new \RuntimeException(sprintf('Story “%s” does not close where it can be found.', $title));
            }

            return [$entryOpen, $entryClose];
        }

        throw new \RuntimeException(sprintf('No story “%s” in this file.', $title));
    }

    private static function skipSpaces(string $source, int $i): int
    {
        $length = strlen($source);
        while ($i < $length && ($source[$i] === ' ' || $source[$i] === "\t")) {
            $i++;
        }

        return $i;
    }

    /**
     * Offsets of the stories hash's own braces.
     *
     * @return array{int, int}
     * @throws \RuntimeException
     */
    private static function storiesHash(string $source): array
    {
        if (preg_match('/\{%-?\s*set\s+stories\s*=\s*\{/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new \RuntimeException('No {% set stories = { … } %} in this file.');
        }

        $open = $m[0][1] + strlen($m[0][0]) - 1; // the hash's own "{"
        $close = self::matchingBrace($source, $open);
        if ($close === null) {
            throw new \RuntimeException('The stories hash in this file does not close where it can be found.');
        }

        return [$open, $close];
    }

    /**
     * The keys written directly inside the hash between $open and $close —
     * not those of hashes nested in it — each as [key, offset of the key,
     * offset just after its colon]. Quoted keys are unescaped.
     *
     * @return list<array{string, int, int}>
     */
    private static function topLevelKeys(string $source, int $open, int $close): array
    {
        $keys = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $c = $source[$i];

            if ($c === '{' && ($source[$i + 1] ?? '') === '#') {
                $end = strpos($source, '#}', $i + 2);
                $i = $end === false ? $close : $end + 1;
                continue;
            }
            if ($c === '#') {
                $end = strpos($source, "\n", $i);
                $i = $end === false ? $close : $end;
                continue;
            }

            if ($c === "'" || $c === '"') {
                $start = $i;
                $raw = '';
                for ($i++; $i < $close && $source[$i] !== $c; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                    $raw .= $source[$i];
                }
                if ($depth === 0) {
                    $colon = self::skipBlank($source, $i + 1);
                    if (($source[$colon] ?? '') === ':') {
                        $keys[] = [$raw, $start, $colon + 1];
                    }
                }
                continue;
            }

            if ($depth === 0 && preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $m, 0, $i) === 1
                && ($i === 0 || preg_match('/[A-Za-z0-9_.]/', $source[$i - 1]) !== 1)
            ) {
                $colon = self::skipBlank($source, $i + strlen($m[0]));
                if (($source[$colon] ?? '') === ':') {
                    $keys[] = [$m[0], $i, $colon + 1];
                }
                $i += strlen($m[0]) - 1;
                continue;
            }

            if ($c === '{' || $c === '[' || $c === '(') {
                $depth++;
            } elseif ($c === '}' || $c === ']' || $c === ')') {
                $depth--;
            }
        }

        return $keys;
    }

    private static function skipBlank(string $source, int $i): int
    {
        $length = strlen($source);
        while ($i < $length && ctype_space($source[$i])) {
            $i++;
        }

        return $i;
    }

    /**
     * Offset of the brace that closes the one at $open, skipping strings, Twig
     * comments and inline `#` comments; null if it never closes.
     */
    private static function matchingBrace(string $source, int $open): ?int
    {
        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $c = $source[$i];

            if ($c === "'" || $c === '"') {
                for ($i++; $i < $length && $source[$i] !== $c; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                }
                continue;
            }

            if ($c === '{' && ($source[$i + 1] ?? '') === '#') {
                $end = strpos($source, '#}', $i + 2);
                if ($end === false) {
                    return null;
                }
                $i = $end + 1;
                continue;
            }

            if ($c === '#') {
                $end = strpos($source, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }

            if ($c === '{' || $c === '[' || $c === '(') {
                $depth++;
            } elseif ($c === '}' || $c === ']' || $c === ')') {
                $depth--;
                if ($depth === 0) {
                    return $c === '}' ? $i : null;
                }
            }
        }

        return null;
    }
}

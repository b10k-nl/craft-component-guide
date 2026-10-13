<?php

namespace b10k\componentguide\helpers;

/**
 * Writes PHP values as Twig literals: `{ key: value }` hashes, `[…]` lists,
 * single-quoted strings.
 *
 * One writer for everything that hands Twig to a developer — the story
 * scaffolder and an editor's shared variant — so the two can never disagree
 * about quoting or indentation.
 */
final class TwigLiteral
{
    public static function value(mixed $value, int $depth = 0): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $pad = str_repeat('    ', $depth);
            $isList = array_is_list($value);
            $lines = [$isList ? '[' : '{'];
            foreach ($value as $key => $item) {
                $prefix = $isList ? '' : self::key((string)$key) . ': ';
                $lines[] = $pad . '    ' . $prefix . self::value($item, $depth + 1) . ',';
            }
            $lines[] = $pad . ($isList ? ']' : '}');

            return implode("\n", $lines);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return self::string((string)$value);
    }

    public static function string(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    public static function key(string $key): string
    {
        return preg_match('/^[a-zA-Z_]\w*$/', $key) === 1 ? $key : self::string($key);
    }
}

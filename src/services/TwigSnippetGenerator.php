<?php

namespace b10k\componentguide\services;

use yii\base\Component;

/**
 * Generates a copy-pasteable Twig `{% include ... with {...} only %}` snippet
 * from a story's args.
 *
 * Handles scalars, null, lists and (nested) associative arrays. Objects fall
 * back to a readable placeholder — the snippet is a guide, not a serializer.
 */
class TwigSnippetGenerator extends Component
{
    public function generate(string $templatePath, array $args): string
    {
        $template = "'" . $this->escape($templatePath) . "'";

        if ($args === []) {
            return "{% include $template only %}";
        }

        $lines = ["{% include $template with {"];
        $entries = [];
        foreach ($args as $key => $value) {
            $entries[] = '    ' . $this->key((string)$key) . ': ' . $this->valueToTwig($value);
        }
        $lines[] = implode(",\n", $entries);
        $lines[] = "} only %}";

        return implode("\n", $lines);
    }

    /**
     * Convert a single PHP value to inline Twig syntax.
     */
    public function valueToTwig(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            return $this->float($value);
        }

        if (is_string($value)) {
            return "'" . $this->escape($value) . "'";
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? $this->list($value)
                : $this->map($value);
        }

        // Unsupported (object/resource): readable placeholder, never raw output.
        return "'" . $this->escape('[' . get_debug_type($value) . ']') . "'";
    }

    /**
     * @param list<mixed> $value
     */
    private function list(array $value): string
    {
        return '[' . implode(', ', array_map(fn($item) => $this->valueToTwig($item), $value)) . ']';
    }

    /**
     * @param array<string, mixed> $value
     */
    private function map(array $value): string
    {
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = $this->key((string)$key) . ': ' . $this->valueToTwig($item);
        }
        return '{ ' . implode(', ', $parts) . ' }';
    }

    private function key(string $key): string
    {
        // Bare key if it is a valid Twig identifier, otherwise quote it.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) === 1) {
            return $key;
        }
        return "'" . $this->escape($key) . "'";
    }

    private function float(float $value): string
    {
        $s = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        return str_contains($s, '.') ? $s : $s . '.0';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}

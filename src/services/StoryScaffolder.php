<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\ComponentDefinition;
use yii\base\Component;

/**
 * Generates a skeleton story file for an undocumented component by analyzing
 * its Twig template: root variables become args, `{% for %}` sources become
 * sample item arrays (with the keys actually used on the loop item), and
 * `|default(...)` / `{% set x = x ?? ... %}` fallbacks become the values.
 * Everything else is guessed from the variable's name, and the first sentence
 * of the template's leading comment becomes the description.
 *
 * Writes either format — `.stories.twig` (default) or `.stories.php` — chosen
 * by the suffix it is handed.
 *
 * Deliberately heuristic (regex, not a Twig lexer): the output is a starting
 * point a developer reviews — marked `status: wip` — not a guaranteed-perfect
 * story. Never overwrites an existing story file.
 */
class StoryScaffolder extends Component
{
    /** Twig language keywords that must never be treated as variables. */
    private const KEYWORDS = [
        'if', 'else', 'elseif', 'endif', 'for', 'endfor', 'in', 'not', 'and',
        'or', 'set', 'endset', 'include', 'extends', 'embed', 'endembed',
        'with', 'only', 'ignore', 'missing', 'block', 'endblock', 'is',
        'defined', 'empty', 'null', 'none', 'true', 'false', 'iterable',
        'even', 'odd', 'same', 'as', 'starts', 'ends', 'matches', 'apply',
        'endapply', 'macro', 'endmacro', 'import', 'from', 'do', 'by',
        'recursive', 'divisible', 'constant',
    ];

    /** Craft/Twig globals that are available without being passed as args. */
    private const GLOBALS = [
        'craft', 'entry', 'now', 'loop', 'currentUser', 'currentSite',
        'siteName', 'siteUrl', 'view', '_self', 'sprig', 'attribute',
    ];

    /**
     * Writes a story scaffold next to the component's template.
     *
     * @return string Absolute path of the created story file.
     * @throws \RuntimeException When the component is already documented, the
     * template is unreadable, the story file already exists, or writing fails.
     */
    public function scaffold(ComponentDefinition $component, string $storySuffix): string
    {
        if ($component->isDocumented) {
            throw new \RuntimeException('This component already has a story file.');
        }

        $template = $component->absoluteTemplatePath;
        if (!is_file($template) || !is_readable($template)) {
            throw new \RuntimeException('The component template could not be read.');
        }

        $storyPath = substr($template, 0, -strlen('.twig')) . $storySuffix;
        if (file_exists($storyPath)) {
            throw new \RuntimeException(sprintf('A story file already exists: %s.', basename($storyPath)));
        }

        $source = file_get_contents($template);
        if ($source === false) {
            throw new \RuntimeException('The component template could not be read.');
        }

        $args = $this->analyze($source);
        $description = $this->describe($source);

        $contents = str_ends_with($storySuffix, '.twig')
            ? $this->renderTwig($component->title, $description, $args)
            : $this->render($component->title, $description, $args);

        if (@file_put_contents($storyPath, $contents) === false) {
            throw new \RuntimeException('Could not write the story file — check filesystem permissions.');
        }

        return $storyPath;
    }

    /**
     * Extracts the template's root variables and guesses a value for each.
     *
     * @return array<string, mixed> Args in first-appearance order.
     */
    public function analyze(string $source): array
    {
        // Comments carry prose, not variables.
        $source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;

        if (preg_match_all('/\{\{(.*?)\}\}|\{%(.*?)%\}/s', $source, $m) === false) {
            return [];
        }

        $expressions = [];
        foreach ([$m[1], $m[2]] as $group) {
            foreach ($group as $expr) {
                $expr = trim($expr);
                if ($expr !== '') {
                    $expressions[] = $expr;
                }
            }
        }

        $locals = ['loop' => true];
        $itemToArray = [];   // loop item variable => its source array variable
        $arrayItemKeys = []; // array variable => [item key => true]
        $order = [];         // root variable => true, in first-appearance order
        $defaults = [];      // root variable => raw Twig |default() literal

        // Pass 1: declarations — what is local, what is a loop source.
        foreach ($expressions as $expr) {
            if (preg_match('/^set\s+([a-zA-Z_]\w*)\s*=(.*)$/s', $expr, $mm) === 1) {
                $name = $mm[1];
                $rhs = trim($mm[2]);
                // Quoted text can contain the name without referencing it.
                $rhsCode = preg_replace('/\'[^\']*\'|"[^"]*"/', '', $rhs) ?? $rhs;
                $selfReferential = preg_match(
                    '/(?<![\w.])' . preg_quote($name, '/') . '(?![\w(])/',
                    $rhsCode,
                ) === 1;

                // `{% set x = x ?? … %}` / `{% set x = x|default(…) %}` is the
                // self-defaulting idiom: x is an incoming argument with a
                // fallback, not a local. (Re-assigning an already-local
                // variable in terms of itself keeps it local.)
                if ($selfReferential && !isset($locals[$name])) {
                    $order[$name] = true;
                    if (preg_match('/\?\?\s*(.+)$/s', $rhs, $dm) === 1) {
                        $defaults[$name] ??= trim($dm[1]);
                    }
                } else {
                    $locals[$name] = true;
                }
            } elseif (preg_match('/^set\s+([a-zA-Z_]\w*)/', $expr, $mm) === 1) {
                // Block capture: {% set x %}…{% endset %} — always local.
                $locals[$mm[1]] = true;
            }
            if (preg_match('/^for\s+([a-zA-Z_]\w*)(?:\s*,\s*([a-zA-Z_]\w*))?\s+in\s+([a-zA-Z_]\w*)/', $expr, $mm) === 1) {
                $locals[$mm[1]] = true;
                $itemVar = $mm[1];
                if (($mm[2] ?? '') !== '') {
                    // "for key, value in …" — the second name is the item.
                    $locals[$mm[2]] = true;
                    $itemVar = $mm[2];
                }
                $itemToArray[$itemVar] = $mm[3];
                $arrayItemKeys[$mm[3]] ??= [];
                $order[$mm[3]] = true;
            }
        }

        // Pass 2: usage — root variables, loop-item keys, default() literals.
        foreach ($expressions as $expr) {
            // Quoted text is data, not code: blank it out (keeping offsets) so
            // CSS classes in a ternary — 'mt-4 lg:mt-10' — don't look like
            // variables. The |default() scan below still sees the original.
            $code = preg_replace_callback(
                '/\'[^\']*\'|"[^"]*"/',
                static fn(array $m): string => str_repeat(' ', strlen($m[0])),
                $expr,
            ) ?? $expr;

            preg_match_all(
                '/(?<![\w.\'"])([a-zA-Z_]\w*)((?:\.[a-zA-Z_]\w*)*)/',
                $code,
                $uses,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );

            foreach ($uses as $use) {
                $root = $use[1][0];
                $path = $use[2][0];
                $offset = (int)$use[0][1];
                $whole = $use[0][0];

                // Filter names (…|filter) are not variables.
                $before = rtrim(substr($code, 0, $offset));
                if ($before !== '' && str_ends_with($before, '|')) {
                    continue;
                }
                // Bare identifiers followed by "(" are function calls.
                $after = ltrim(substr($code, $offset + strlen($whole)));
                if ($path === '' && $after !== '' && $after[0] === '(') {
                    continue;
                }
                if (in_array(strtolower($root), self::KEYWORDS, true)
                    || in_array($root, self::GLOBALS, true)
                ) {
                    continue;
                }

                if (isset($itemToArray[$root])) {
                    if ($path !== '') {
                        $key = explode('.', ltrim($path, '.'))[0];
                        $arrayItemKeys[$itemToArray[$root]][$key] = true;
                    }
                    continue;
                }

                if (isset($locals[$root])) {
                    continue;
                }

                $order[$root] = true;
            }

            // |default(…) literals on plain roots become the guessed value.
            preg_match_all(
                '/([a-zA-Z_]\w*)((?:\.[a-zA-Z_]\w*)*)\s*\|\s*default\(((?:[^()\'"]|\'[^\']*\'|"[^"]*")*)\)/',
                $expr,
                $defs,
                PREG_SET_ORDER,
            );
            foreach ($defs as $d) {
                if ($d[2] === '' && !isset($locals[$d[1]]) && !isset($defaults[$d[1]])) {
                    $defaults[$d[1]] = trim($d[3]);
                }
            }
        }

        $args = [];
        foreach (array_keys($order) as $name) {
            if (isset($locals[$name])) {
                continue;
            }
            if (isset($arrayItemKeys[$name])) {
                $args[$name] = $this->sampleItems(array_keys($arrayItemKeys[$name]));
                continue;
            }
            $args[$name] = array_key_exists($name, $defaults)
                ? $this->literalOrGuess($defaults[$name], $name)
                : $this->guessValue($name);
        }

        return $args;
    }

    /**
     * Pulls a human description out of the template's leading `{# … #}`
     * comment: the first sentence only, since the rest is usually developer
     * notes (design links, param tables, TODOs).
     */
    public function describe(string $source): ?string
    {
        // Only a comment at the very top documents the component itself.
        if (preg_match('/^\s*\{#(.*?)#\}/s', $source, $m) !== 1) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $m[1]) ?? '');
        if ($text === '') {
            return null;
        }

        // Require a few characters before the first terminator so common
        // abbreviations don't cut the sentence short.
        if (preg_match('/^(.{12,240}?[.!?])(?:\s|$)/u', $text, $s) === 1) {
            return trim($s[1]);
        }

        return mb_strlen($text) > 240 ? rtrim(mb_substr($text, 0, 240)) . '…' : $text;
    }

    /**
     * Renders the PHP story-file source (rich format, one "Default" story,
     * status "wip" so the guide flags it as a draft).
     *
     * @param array<string, mixed> $args
     */
    public function render(string $title, ?string $description, array $args): string
    {
        return sprintf(
            <<<'PHP'
            <?php

            /**
             * Story scaffold generated by Component Guide from the template's variables.
             * The args below are guesses — review them until the preview looks right,
             * then promote the status when the component is documented for real.
             */

            return [
                'meta' => %s,
                'stories' => [
                    'Default' => [
                        'args' => %s,
                    ],
                ],
            ];

            PHP,
            $this->export($this->meta($title, $description), 1),
            $this->export($args, 3),
        );
    }

    /**
     * Renders the Twig story-template source — pure data, same shape as the
     * PHP format, in the language the component itself is written in.
     *
     * @param array<string, mixed> $args
     */
    public function renderTwig(string $title, ?string $description, array $args): string
    {
        return sprintf(
            <<<'TWIG'
            {# Story scaffold generated by Component Guide from the template's variables.
               The args below are guesses — review them until the preview looks right,
               then promote the status when the component is documented for real. #}

            {%% set meta = %s %%}

            {%% set stories = {
                'Default': {
                    args: %s,
                },
            } %%}

            TWIG,
            $this->exportTwig($this->meta($title, $description), 0),
            $this->exportTwig($args, 2),
        );
    }

    /**
     * @return array<string, string>
     */
    private function meta(string $title, ?string $description): array
    {
        $meta = ['title' => $title];
        if ($description !== null && $description !== '') {
            $meta['description'] = $description;
        }
        // Always a draft: the args are guesses until a human confirms them.
        $meta['status'] = 'wip';

        return $meta;
    }

    /**
     * @param string[] $keys Keys observed on the loop item.
     * @return array<int, array<string, mixed>>
     */
    private function sampleItems(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $item = [];
            foreach ($keys as $key) {
                $value = $this->guessValue($key);
                // Vary plain-text samples so list previews look alive.
                if (is_string($value) && $value !== '#'
                    && !str_starts_with($value, '<')
                    && !str_starts_with($value, 'data:')
                ) {
                    $value .= ' ' . $i;
                }
                $item[$key] = $value;
            }
            $items[] = $item;
        }

        return $items;
    }

    private function guessValue(string $name): mixed
    {
        $n = strtolower($name);

        if (str_ends_with($n, 'url') && preg_match('/(image|img|photo|picture|logo|icon|avatar|thumb|media)/', $n) === 1) {
            return 'data:image/svg+xml,' . rawurlencode(
                '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="260">'
                . '<rect width="100%" height="100%" fill="#e2e8f0"/>'
                . '<path d="M0 260 130 130l78 78 65-65 127 117z" fill="#94a3b8"/>'
                . '<circle cx="310" cy="70" r="36" fill="#cbd5e1"/>'
                . '</svg>'
            );
        }
        if (str_ends_with($n, 'url') || str_ends_with($n, 'href') || str_ends_with($n, 'link')) {
            return '#';
        }
        if (str_ends_with($n, 'html') || str_starts_with($n, 'body') || str_ends_with($n, 'text') || $n === 'quote') {
            return '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt.</p>';
        }
        if (str_ends_with($n, 'alt')) {
            return 'Placeholder image';
        }
        if (preg_match('/^(is|has|show|enable)[a-z0-9_]/', $n) === 1) {
            return true;
        }
        if (preg_match('/(count|limit|columns|total)$/', $n) === 1) {
            return 3;
        }
        if ($n === 'id' || str_ends_with($n, 'id')) {
            return 12345;
        }
        if (str_ends_with($n, 'ids')) {
            return [];
        }

        return 'Lorem ipsum';
    }

    /**
     * Converts a Twig |default() literal into a PHP value, falling back to a
     * name-based guess for anything that is not a plain literal.
     */
    private function literalOrGuess(string $literal, string $name): mixed
    {
        if ($literal === '' || $literal === "''" || $literal === '""') {
            return $this->guessValue($name);
        }
        if (preg_match('/^\'(.*)\'$/s', $literal, $m) === 1 || preg_match('/^"(.*)"$/s', $literal, $m) === 1) {
            return $m[1];
        }
        if (is_numeric($literal)) {
            return str_contains($literal, '.') ? (float)$literal : (int)$literal;
        }
        if ($literal === 'true' || $literal === 'false') {
            return $literal === 'true';
        }
        if ($literal === 'null' || $literal === 'none') {
            return null;
        }
        if ($literal === '[]') {
            return [];
        }

        // An expression (entry.title, foo ~ bar, …) — not reproducible here.
        return $this->guessValue($name);
    }

    /**
     * var_export-style output with short array syntax and 4-space indents.
     */
    private function export(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $pad = str_repeat('    ', $depth);
            $isList = array_is_list($value);
            $lines = ['['];
            foreach ($value as $key => $item) {
                $prefix = $isList ? '' : var_export((string)$key, true) . ' => ';
                $lines[] = $pad . '    ' . $prefix . $this->export($item, $depth + 1) . ',';
            }
            $lines[] = $pad . ']';

            return implode("\n", $lines);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        return var_export($value, true);
    }

    /**
     * Twig-literal output: `{ key: value }` hashes, `[…]` lists, single-quoted
     * strings.
     */
    private function exportTwig(mixed $value, int $depth): string
    {
        if (is_array($value)) {
            if ($value === []) {
                return '[]';
            }

            $pad = str_repeat('    ', $depth);
            $isList = array_is_list($value);
            $lines = [$isList ? '[' : '{'];
            foreach ($value as $key => $item) {
                $prefix = $isList ? '' : $this->twigKey((string)$key) . ': ';
                $lines[] = $pad . '    ' . $prefix . $this->exportTwig($item, $depth + 1) . ',';
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

        return $this->twigString((string)$value);
    }

    private function twigString(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    private function twigKey(string $key): string
    {
        return preg_match('/^[a-zA-Z_]\w*$/', $key) === 1 ? $key : $this->twigString($key);
    }
}

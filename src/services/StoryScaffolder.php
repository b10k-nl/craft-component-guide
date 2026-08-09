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
 * point a developer reviews — marked `status: draft` — not a guaranteed-perfect
 * story. Never overwrites an existing story file.
 */
class StoryScaffolder extends Component
{
    /** Twig language keywords that must never be treated as variables. */
    private const KEYWORDS = [
        'if', 'else', 'elseif', 'endif', 'for', 'endfor', 'in', 'not', 'and',
        'or', 'set', 'endset', 'include', 'extends', 'embed', 'endembed',
        'with', 'only', 'ignore', 'missing', 'is',
        'defined', 'empty', 'null', 'none', 'true', 'false', 'iterable',
        'even', 'odd', 'same', 'as', 'starts', 'ends', 'matches', 'apply',
        'endapply', 'macro', 'endmacro', 'import', 'from', 'do', 'by',
        'recursive', 'divisible', 'constant',
    ];

    /**
     * Tags whose expression declares names instead of using props — skipped
     * wholesale. This is also how `{% block content %}` stays out of the args
     * while `{{ block.heading }}` stays in: in Craft page-builder templates
     * `block` is almost always the Matrix block itself, not Twig's tag.
     */
    private const DECLARATION_TAGS = [
        'block', 'endblock', 'macro', 'endmacro', 'import', 'from', 'use',
        'extends',
    ];

    /** Appended to a scaffold whose template needs data no story can supply. */
    private const RUNTIME_DATA_NOTE = 'Heads up: this template calls methods on its variables (or reads Craft globals such as entry/craft), which a story cannot provide — expect a partial or empty preview. Consider moving the markup into a presentational partial that takes plain values; see "Recommended architecture" in the plugin README.';

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
    public function scaffold(ComponentDefinition $component, string $storySuffix, bool $withStates = false): string
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
        $warning = $this->needsRuntimeData($source) ? self::RUNTIME_DATA_NOTE : null;

        $stories = ['Default' => $args];
        if ($withStates) {
            $state = $this->detectStates($source);
            if ($state !== null && array_key_exists($state['var'], $args)) {
                $stories = [];
                foreach ($state['values'] as $value) {
                    $stories[$this->stateName($state['var'], $value)] = [$state['var'] => $value] + $args;
                }
            }
        }

        $contents = str_ends_with($storySuffix, '.twig')
            ? $this->renderTwig($component->title, $description, $stories, $warning)
            : $this->render($component->title, $description, $stories, $warning);

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
        $objectPaths = [];   // variable => list of dotted paths used on it
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
                // The trailing capture group keeps offset 2 present (empty when
                // the "key, value" form isn't used), so no ?? needed.
                if ($mm[2] !== '') {
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
            // `{% block x %}` and friends declare names, not props.
            if (preg_match('/^([a-zA-Z_]\w*)(\s|$)/', $expr, $tag) === 1
                && in_array(strtolower($tag[1]), self::DECLARATION_TAGS, true)
            ) {
                continue;
            }

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
                $isCall = $after !== '' && $after[0] === '(';
                if ($path === '' && $isCall) {
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

                // Dotted access means the story should hand over a hash with
                // these keys — in Twig `block.heading` reads the same off a
                // plain hash as off an Entry, so a story can stand in for one.
                if ($path !== '') {
                    $segments = explode('.', ltrim($path, '.'));
                    if ($isCall) {
                        // `layout.urls.all()`: the trailing method can't be
                        // faked, but the prefix is still worth declaring.
                        array_pop($segments);
                    }
                    if ($segments !== []) {
                        $objectPaths[$root][] = $segments;
                    }
                }
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
            if (isset($objectPaths[$name])) {
                $args[$name] = $this->sampleObject($objectPaths[$name]);
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
     * Whether the template needs data a story fundamentally cannot supply:
     * method calls on its variables, or Craft's own globals.
     *
     * Dotted access alone (`block.heading`) is fine — a plain hash stands in
     * for an element there — so it is deliberately not a reason to warn.
     */
    public function needsRuntimeData(string $source): bool
    {
        $code = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
        $code = preg_replace('/\'[^\']*\'|"[^"]*"/', '', $code) ?? $code;

        return preg_match('/(?<![\w.])(entry|craft|currentUser)\s*\./', $code) === 1
            || preg_match('/\.[a-zA-Z_]\w*\s*\(/', $code) === 1;
    }

    /**
     * Finds the template's own “switch” — an argument compared against two or
     * more string literals (`theme == 'dark'`, `mediaPosition == 'right'`).
     * Those comparisons are the component's documented states, so one story
     * per value beats one story that shows a single path through the markup.
     *
     * Only one switch is used: two enums would multiply into a story matrix
     * nobody asked for. The variable with the most values wins.
     *
     * @return array{var: string, values: string[]}|null
     */
    public function detectStates(string $source): ?array
    {
        $source = preg_replace('/\{#.*?#\}/s', '', $source) ?? $source;
        if (preg_match_all('/\{\{(.*?)\}\}|\{%(.*?)%\}/s', $source, $m) === false) {
            return null;
        }

        $byVar = [];
        $fallbacks = [];
        foreach (array_merge($m[1], $m[2]) as $expr) {
            // `theme == 'dark'`, `mediaPosition != 'background'`
            preg_match_all(
                '/(?<![\w.])([a-zA-Z_]\w*)\s*[!=]=\s*\'([^\']*)\'/',
                $expr,
                $comparisons,
                PREG_SET_ORDER,
            );
            foreach ($comparisons as $c) {
                if (in_array(strtolower($c[1]), self::KEYWORDS, true) || $c[2] === '') {
                    continue;
                }
                $byVar[$c[1]][$c[2]] = true;
            }

            // The fallback counts as a state too: `theme ?? 'light'`. Collected
            // separately — the `{% set %}` that carries it usually precedes the
            // comparison that makes the variable interesting.
            if (preg_match('/(?<![\w.])([a-zA-Z_]\w*)\s*(?:\|\s*default\(|\?\?\s*)\'([^\']+)\'/', $expr, $d) === 1) {
                $fallbacks[$d[1]] ??= $d[2];
            }
        }

        foreach ($fallbacks as $var => $value) {
            if (isset($byVar[$var])) {
                $byVar[$var][$value] ??= true;
            }
        }

        $best = null;
        foreach ($byVar as $var => $values) {
            if (count($values) < 2) {
                continue;
            }
            if ($best === null || count($values) > count($byVar[$best])) {
                $best = $var;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'var' => $best,
            // Four states is plenty for a scaffold; more is a sign the guess
            // went wrong, not that the component has ten looks.
            'values' => array_slice(array_keys($byVar[$best]), 0, 4),
        ];
    }

    /**
     * “theme” + “dark” → “Dark”; “mediaPosition” + “right” → “Media right”.
     * Modifier-ish variable names add nothing to the label, everything else
     * lends its first word so the story name stays self-explanatory.
     */
    private function stateName(string $var, string $value): string
    {
        $label = strtolower(str_replace(['-', '_'], ' ', $value));
        $plain = ['theme', 'variant', 'style', 'type', 'mode', 'size', 'color', 'colour', 'state'];

        if (in_array(strtolower($var), $plain, true)) {
            return ucfirst($label);
        }

        $words = strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $var) ?? $var));
        $lead = explode(' ', $words)[0];

        return ucfirst($lead . ' ' . $label);
    }

    /**
     * Renders the PHP story-file source (rich format, status "draft" so the
     * guide flags it as unreviewed).
     *
     * @param array<string, array<string, mixed>> $stories Story name => args.
     */
    public function render(string $title, ?string $description, array $stories, ?string $warning = null): string
    {
        return sprintf(
            <<<'PHP'
            <?php

            /**
             * Story scaffold generated by Component Guide from the template's variables.
             * The args below are guesses — review them until the preview looks right,
             * then promote the status when the component is documented for real.%s
             */

            return [
                'meta' => %s,
                'stories' => %s,
            ];

            PHP,
            $warning !== null ? "\n *\n * " . wordwrap($warning, 74, "\n * ") : '',
            $this->export($this->meta($title, $description), 1),
            $this->export($this->wrapStories($stories), 1),
        );
    }

    /**
     * Renders the Twig story-template source — pure data, same shape as the
     * PHP format, in the language the component itself is written in.
     *
     * @param array<string, array<string, mixed>> $stories Story name => args.
     */
    public function renderTwig(string $title, ?string $description, array $stories, ?string $warning = null): string
    {
        return sprintf(
            <<<'TWIG'
            {# Story scaffold generated by Component Guide from the template's variables.
               The args below are guesses — review them until the preview looks right,
               then promote the status when the component is documented for real.%s #}

            {%% set meta = %s %%}

            {%% set stories = %s %%}

            TWIG,
            $warning !== null ? "\n\n   " . wordwrap($warning, 74, "\n   ") : '',
            $this->exportTwig($this->meta($title, $description), 0),
            $this->exportTwigStories($stories),
        );
    }

    /**
     * Story names are labels, not identifiers, so they are always quoted —
     * otherwise `Default:` and `'Media right':` would sit side by side in the
     * same file.
     *
     * @param array<string, array<string, mixed>> $stories
     */
    private function exportTwigStories(array $stories): string
    {
        $lines = ['{'];
        foreach ($stories as $name => $args) {
            $lines[] = '    ' . $this->twigString($name) . ': {';
            $lines[] = '        args: ' . $this->exportTwig($args, 2) . ',';
            $lines[] = '    },';
        }
        $lines[] = '}';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<string, mixed>> $stories
     * @return array<string, array{args: array<string, mixed>}>
     */
    private function wrapStories(array $stories): array
    {
        $wrapped = [];
        foreach ($stories as $name => $args) {
            $wrapped[$name] = ['args' => $args];
        }

        return $wrapped;
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
        $meta['status'] = 'draft';

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
                // Tokens are seeded per array index at render time, so three
                // identical tokens still produce three different samples.
                $item[$key] = $this->guessValue($key);
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Builds a stand-in hash for a variable accessed by dotted paths, so
     * templates written against an element (`block.heading`) still render.
     *
     * @param array<int, string[]> $chains Path segments, outermost first.
     * @return array<string, mixed>
     */
    private function sampleObject(array $chains): array
    {
        $out = [];
        foreach ($chains as $segments) {
            $out = $this->setPath($out, $segments);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $target
     * @param string[] $segments
     */
    private function setPath(array $target, array $segments, string $context = ''): array
    {
        $key = array_shift($segments);
        if ($key === null) {
            return $target;
        }

        if ($segments === []) {
            if (!array_key_exists($key, $target)) {
                // The leaf alone can be ambiguous (`block.image.url` ends in a
                // plain "url") — let the path lend it context.
                $target[$key] = $this->guessValue($key, $context);
            }
            return $target;
        }

        $child = (isset($target[$key]) && is_array($target[$key])) ? $target[$key] : [];
        $target[$key] = $this->setPath($child, $segments, trim($context . ' ' . $key));

        return $target;
    }

    /**
     * @param string $context Ancestor path segments ("block image") that lend
     *        meaning to ambiguous leaf names like "url" or "alt".
     */
    private function guessValue(string $name, string $context = ''): mixed
    {
        $n = strtolower($name);
        $scope = strtolower(trim($context . ' ' . $name));

        // Values are emitted as placeholder tokens rather than baked-in text:
        // the story file stays short and readable, and PlaceholderResolver
        // expands them deterministically at render time.
        if (str_ends_with($n, 'url') && preg_match('/(image|img|photo|picture|logo|icon|avatar|thumb|media)/', $scope) === 1) {
            if (preg_match('/(icon|avatar|logo)/', $scope) === 1) {
                return '@icon';
            }
            if (preg_match('/(hero|banner|cover)/', $scope) === 1) {
                return '@image_1600x600';
            }
            return '@image';
        }
        if (str_ends_with($n, 'url') || str_ends_with($n, 'href') || str_ends_with($n, 'link')) {
            return '#';
        }
        if (str_ends_with($n, 'html') || str_starts_with($n, 'body') || $n === 'quote') {
            return '@lorem_p_1';
        }
        if (str_ends_with($n, 'text') || str_starts_with($n, 'description')) {
            return '@lorem_s_2';
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
        if (preg_match('/(heading|title|label|name|question|eyebrow|kicker|badge)$/', $n) === 1) {
            return '@lorem_w_4';
        }

        return '@lorem';
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

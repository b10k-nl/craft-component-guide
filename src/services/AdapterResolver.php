<?php

namespace b10k\componentguide\services;

use b10k\componentguide\models\AdapterBinding;
use yii\base\Component;

/**
 * Recovers, from the adapter, the mapping between a component's arguments and
 * the entry fields that feed them.
 *
 * Why this exists. A story names presentational arguments (`bodyHtml`); a Craft
 * entry type names fields (`bodyText`); neither file says they are the same
 * thing. The adapter's include site says it outright:
 *
 *     {% include '_blocks/hero.twig' with { bodyHtml: block.bodyText } only %}
 *
 * Until now the picker guessed that mapping from a naming convention, which is
 * right only when the developer happened to follow the same convention. The
 * answer was written down all along, two files away.
 *
 * An adapter is not recognised by the folder it sits in — blocks live wherever
 * an adapter includes them, and judging a folder by its name has misled us
 * before. It is recognised by behaviour: a template that includes the component
 * and reads properties off a variable.
 */
class AdapterResolver extends Component
{
    /**
     * Attributes every entry carries, so a reference to one is never a missing
     * field. `title` belongs here: it always exists, and adapters legitimately
     * fall back to it.
     */
    public const ELEMENT_ATTRIBUTES = [
        'ancestors', 'author', 'authorId', 'children', 'dateCreated', 'dateUpdated',
        'descendants', 'enabled', 'expiryDate', 'fieldLayout', 'id', 'level', 'next',
        'owner', 'parent', 'postDate', 'prev', 'primaryOwner', 'ref', 'section',
        'siblings', 'site', 'siteId', 'slug', 'sortOrder', 'status', 'title',
        'type', 'uid', 'uri', 'url',
    ];

    /** Roots that are never the block variable. */
    private const GLOBAL_ROOTS = ['craft', 'loop', 'now', 'app', '_self', '_context', 'this'];

    /**
     * Parse one template and, if it includes the given component, describe the
     * binding it declares.
     *
     * Pure: source in, value object out. No filesystem, no Craft — the rules are
     * testable on strings.
     */
    public function parse(string $source, string $componentTemplate, string $adapterPath = ''): ?AdapterBinding
    {
        $tags = $this->tags($source);
        $include = null;

        foreach ($tags as $tag) {
            if ($tag['name'] !== 'include') {
                continue;
            }
            $template = $this->literalTemplate($tag['inner']);
            if ($template !== null && $this->sameTemplate($template, $componentTemplate)) {
                $include = $tag;
                break;
            }
        }

        if ($include === null) {
            return null;
        }

        $args = $this->withMap($include['inner']);

        // Two ways an adapter iterates a nested field. A `{% for %}` tag binds
        // its variable for the whole template and reads the nested entry in the
        // loop body; an arrow function binds inside one argument and reads it
        // there. Both occur in the wild, so both are resolved.
        $forLoops = $this->forLoops($tags);
        $forBodyFields = [];
        foreach (array_keys($forLoops) as $var) {
            $forBodyFields[$var] = $this->bodyReferences($source, $var);
        }

        $binding = new AdapterBinding(
            adapterPath: $adapterPath,
            args: $args,
            only: $this->hasOnly($include['inner']),
        );

        $binding->blockRoot = $this->blockRoot($args, array_keys($forLoops));

        foreach ($args as $arg => $expression) {
            $loopVars = array_merge($this->arrowVars($expression), array_keys($forLoops));

            $blockFields = [];
            $nestedFields = [];

            foreach ($this->references($expression) as [$root, $property]) {
                if ($binding->blockRoot !== '' && $root === $binding->blockRoot) {
                    $blockFields[] = $property;
                } elseif (in_array($root, $loopVars, true)) {
                    $nestedFields[$root][] = $property;
                }
            }

            if ($blockFields !== []) {
                $binding->blockFields[$arg] = $this->unique($blockFields);
            }

            foreach ($nestedFields as $var => $properties) {
                $nestedFields[$var] = array_merge($properties, $forBodyFields[$var] ?? []);
            }

            $var = array_key_first($nestedFields);
            if ($var !== null) {
                $binding->nested[$arg] = [
                    'var' => (string)$var,
                    'sourceField' => $this->nestedSource(
                        $binding->blockFields[$arg] ?? [],
                        $forLoops[$var] ?? null,
                    ),
                    'fields' => $this->unique($nestedFields[$var]),
                ];
            }
        }

        return $binding;
    }

    /**
     * True when a property read on an entry is a real field rather than one of
     * Craft's own attributes.
     */
    public static function isFieldHandle(string $property): bool
    {
        return !in_array($property, self::ELEMENT_ATTRIBUTES, true);
    }

    // --- Tag scanning -------------------------------------------------------

    /**
     * Every `{% … %}` tag as name plus inner text. Quote-aware, so a `%}` inside
     * a string literal cannot end the tag early.
     *
     * @return list<array{name: string, inner: string}>
     */
    private function tags(string $source): array
    {
        $tags = [];
        $length = strlen($source);
        $i = 0;

        while (($start = strpos($source, '{%', $i)) !== false) {
            $j = $start + 2;
            $quote = null;

            while ($j < $length) {
                $char = $source[$j];

                if ($quote !== null) {
                    if ($char === '\\') {
                        $j += 2;
                        continue;
                    }
                    if ($char === $quote) {
                        $quote = null;
                    }
                    $j++;
                    continue;
                }

                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    $j++;
                    continue;
                }

                if ($char === '%' && ($source[$j + 1] ?? '') === '}') {
                    break;
                }

                $j++;
            }

            if ($j >= $length) {
                break;
            }

            $inner = trim(rtrim(trim(substr($source, $start + 2, $j - $start - 2)), '-'));
            $name = strtolower((string)(preg_split('/\s+/', $inner)[0] ?? ''));

            $tags[] = ['name' => $name, 'inner' => $inner];
            $i = $j + 2;
        }

        return $tags;
    }

    /**
     * The quoted template name of an include, or null when it is computed — a
     * dynamic include tells us nothing we can check.
     */
    private function literalTemplate(string $inner): ?string
    {
        $rest = trim(substr($inner, strlen('include')));

        return preg_match('/^([\'"])(.*?)\1/', $rest, $match) === 1 ? $match[2] : null;
    }

    private function sameTemplate(string $a, string $b): bool
    {
        $normalise = static function (string $path): string {
            $path = ltrim(str_replace('\\', '/', trim($path)), './');

            return str_ends_with($path, '.twig') ? substr($path, 0, -5) : $path;
        };

        return $normalise($a) === $normalise($b);
    }

    private function hasOnly(string $inner): bool
    {
        return preg_match('/(^|[\s}\)])only$/', $inner) === 1;
    }

    // --- The `with` map -----------------------------------------------------

    /**
     * Top-level keys of `with { … }` mapped to their raw expressions.
     *
     * @return array<string, string>
     */
    private function withMap(string $inner): array
    {
        if (preg_match('/(^|\s)with\s*\{/', $inner, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $open = strpos($inner, '{', $match[0][1]);
        if ($open === false) {
            return [];
        }

        $body = $this->balanced($inner, $open);
        if ($body === null) {
            return [];
        }

        $args = [];
        foreach ($this->splitTopLevel($body) as $entry) {
            $colon = $this->topLevelColon($entry);
            if ($colon === null) {
                continue;
            }

            $key = trim(trim(substr($entry, 0, $colon)), "'\" \t\n\r");
            $expression = trim(substr($entry, $colon + 1));

            if ($key !== '' && $expression !== '') {
                $args[$key] = $expression;
            }
        }

        return $args;
    }

    /** Contents of the bracket opened at $open, excluding the brackets. */
    private function balanced(string $source, int $open): ?string
    {
        $pairs = ['{' => '}', '[' => ']', '(' => ')'];
        $stack = [];
        $quote = null;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if (isset($pairs[$char])) {
                $stack[] = $pairs[$char];
                continue;
            }

            if ($stack !== [] && $char === end($stack)) {
                array_pop($stack);
                if ($stack === []) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    /**
     * Split on commas outside brackets and strings.
     *
     * @return list<string>
     */
    private function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $quote = null;
        $current = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $body[++$i];
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === '{' || $char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === '}' || $char === ']' || $char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    /**
     * Offset of the `key: value` colon, skipping colons inside brackets,
     * strings and arrow bodies.
     */
    private function topLevelColon(string $entry): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($entry);

        for ($i = 0; $i < $length; $i++) {
            $char = $entry[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === '{' || $char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === '}' || $char === ']' || $char === ')') {
                $depth--;
            } elseif ($char === ':' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    // --- References ---------------------------------------------------------

    /**
     * `root.property` pairs, first hop only, with strings removed so a dotted
     * literal cannot masquerade as a field read.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function references(string $expression): array
    {
        $found = [];

        preg_match_all(
            '/(?<![\w.])([A-Za-z_]\w*)\.([A-Za-z_]\w*)/',
            $this->stripStrings($expression),
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as $match) {
            $found[] = [$match[1], $match[2]];
        }

        return $found;
    }

    private function stripStrings(string $expression): string
    {
        return (string)preg_replace('/([\'"])(?:\\\\.|(?!\1).)*\1/', "''", $expression);
    }

    /**
     * Variables bound by an arrow function inside one expression:
     * `block.quotes.all()|map(it => { … })` binds `it`.
     *
     * @return list<string>
     */
    private function arrowVars(string $expression): array
    {
        preg_match_all(
            '/(?<![\w.])([A-Za-z_]\w*)\s*=>/',
            $this->stripStrings($expression),
            $matches,
        );

        return $this->unique($matches[1]);
    }

    /**
     * Variables bound by a `{% for … %}` tag, mapped to the block field they
     * iterate. Method calls and filters after the field are ignored:
     * `block.cards.all()` still iterates `cards`.
     *
     * @param list<array{name: string, inner: string}> $tags
     * @return array<string, string>
     */
    private function forLoops(array $tags): array
    {
        $loops = [];

        foreach ($tags as $tag) {
            if ($tag['name'] !== 'for') {
                continue;
            }

            $pattern = '/^for\s+(?:[A-Za-z_]\w*\s*,\s*)?([A-Za-z_]\w*)\s+in\s+[A-Za-z_]\w*\.([A-Za-z_]\w*)/';
            if (preg_match($pattern, $tag['inner'], $match) === 1) {
                $loops[$match[1]] = $match[2];
            }
        }

        return $loops;
    }

    /**
     * Properties read on a loop variable anywhere in the template — in a
     * `{% for %}` the nested entry is used in the body, not in the include map.
     *
     * @return list<string>
     */
    private function bodyReferences(string $source, string $var): array
    {
        preg_match_all(
            '/(?<![\w.])' . preg_quote($var, '/') . '\.([A-Za-z_]\w*)/',
            $this->stripStrings($source),
            $matches,
        );

        return $this->unique($matches[1]);
    }

    /**
     * Which block field a nested iteration draws from. A `{% for %}` names it
     * directly; for an arrow function it is the one real field read in the same
     * expression — `fieldLayout` guards and the like are not fields. Two
     * candidates mean we cannot say, and saying nothing beats guessing.
     *
     * @param string[] $blockFieldsForArg
     */
    private function nestedSource(array $blockFieldsForArg, ?string $forLoopField): ?string
    {
        if ($forLoopField !== null) {
            return $forLoopField;
        }

        $candidates = array_values(array_filter($blockFieldsForArg, self::isFieldHandle(...)));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The variable the adapter reads the block from: the most frequent root
     * that is neither a loop variable nor a Twig global. Observed rather than
     * assumed, because the dispatcher chooses the name.
     *
     * @param array<string, string> $args
     * @param string[] $forVars
     */
    private function blockRoot(array $args, array $forVars): string
    {
        $counts = [];

        foreach ($args as $expression) {
            $arrowVars = $this->arrowVars($expression);

            foreach ($this->references($expression) as [$root, $property]) {
                if (in_array($root, $forVars, true)
                    || in_array($root, $arrowVars, true)
                    || in_array($root, self::GLOBAL_ROOTS, true)
                ) {
                    continue;
                }
                $counts[$root] = ($counts[$root] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return '';
        }

        arsort($counts);

        return (string)array_key_first($counts);
    }

    /**
     * @param string[] $values
     * @return list<string>
     */
    private function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}

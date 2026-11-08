<?php

namespace b10k\componentguide\mcp;

use Craft;

/**
 * Asks Craft's MCP whether our pieces still fit, before handing them over.
 *
 * Craft reads a capability class itself, through its attributes; if a later
 * Craft or MCP SDK changes what those attributes accept, a class that no
 * longer fits does not fail alone — the whole server stops answering. So the
 * tools go through Craft's own registry here first, and the check's interface
 * is compared before our class implementing it is ever loaded (a class that
 * no longer matches its interface is a fatal error, not an exception).
 *
 * Whatever does not fit is left out and logged; the site's MCP server goes on
 * without it.
 */
final class McpCompat
{
    private static ?bool $tools = null;

    /** Craft's registry builds our tools, both of them, without an error. */
    public static function toolsFit(): bool
    {
        if (self::$tools !== null) {
            return self::$tools;
        }

        try {
            $names = array_keys((new \craft\mcp\CapabilityRegistry([Tools::class]))->definitions()->getTools());
            $missing = array_diff(['component-guide.stories', 'component-guide.render'], $names);
            if ($missing !== []) {
                throw new \LogicException('not found: ' . implode(', ', $missing));
            }
            return self::$tools = true;
        } catch (\Throwable $e) {
            self::warn('its tools', $e);
            return self::$tools = false;
        }
    }

    /** The content-model check interface is still the one ContractCheck implements. */
    public static function checkFits(): bool
    {
        try {
            $interface = new \ReflectionClass(\craft\mcp\contentmodel\Check::class);
            $methods = array_map(static fn(\ReflectionMethod $m): string => $m->getName(), $interface->getMethods());
            sort($methods);
            $id = $interface->getMethod('id');
            $run = $interface->getMethod('run');
            $limit = $run->getParameters()[0] ?? null;
            $idType = $id->getReturnType();
            $runType = $run->getReturnType();

            $fits = $methods === ['id', 'run']
                && $id->isStatic() && $id->getNumberOfParameters() === 0
                && ($idType === null || (string)$idType === 'string')
                && $run->getNumberOfParameters() === 1
                && $limit !== null && (string)$limit->getType() === 'int'
                && ($runType === null || (string)$runType === 'array');
            if (!$fits) {
                throw new \LogicException('craft\mcp\contentmodel\Check has changed.');
            }
            return true;
        } catch (\Throwable $e) {
            self::warn('its content-model check', $e);
            return false;
        }
    }

    private static function warn(string $what, \Throwable $e): void
    {
        Craft::warning(sprintf(
            'Component Guide left %s out of Craft’s MCP server (Craft %s): %s',
            $what,
            Craft::$app->getVersion(),
            $e->getMessage(),
        ), 'component-guide');
    }
}

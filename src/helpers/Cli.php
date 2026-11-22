<?php

namespace b10k\componentguide\helpers;

use CraftCms\Cms\Console\TerminalCommands;

/**
 * Terminal commands the plugin prints, in the form this environment runs
 * them: `ddev artisan craft:…` on DDEV, `php artisan craft:…` otherwise.
 * Craft 6 decides (TerminalCommands), so messages match Craft's own.
 */
final class Cli
{
    /**
     * A Craft command, e.g. `craft('component-check:test hero --json')`.
     */
    public static function craft(string $command): string
    {
        return app(TerminalCommands::class)->craft($command);
    }

    /**
     * How Artisan is run here, e.g. `ddev artisan` — for text that names
     * several commands after it.
     */
    public static function artisan(): string
    {
        return rtrim(app(TerminalCommands::class)->artisan(''));
    }

    /**
     * A Composer command, e.g. `composer('require b10k/craft-component-map')`.
     */
    public static function composer(string $command): string
    {
        return app(TerminalCommands::class)->composer($command);
    }
}

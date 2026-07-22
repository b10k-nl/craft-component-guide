<?php

/**
 * PHPUnit bootstrap.
 *
 * The scanner/parser/snippet services are Craft-free, so tests only need the
 * Composer autoloader plus Yii's base class (services extend yii\base\Component).
 *
 * Uses the plugin's own vendor/ when present (standalone/CI: `composer install`
 * inside the plugin), and otherwise falls back to the host Craft project's
 * autoloader so the suite runs without a second full install.
 */

$pluginAutoload = __DIR__ . '/../vendor/autoload.php';
$rootAutoload = dirname(__DIR__, 4) . '/vendor/autoload.php';

if (is_file($pluginAutoload)) {
    $autoload = require $pluginAutoload;
    $yiiRoot = __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
} elseif (is_file($rootAutoload)) {
    $autoload = require $rootAutoload;
    $yiiRoot = dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';
    // The host autoloader may not know this plugin's namespace; register it.
    $autoload->addPsr4('b10k\\componentguide\\', __DIR__ . '/../src/');
} else {
    fwrite(STDERR, "No Composer autoloader found. Run `composer install`.\n");
    exit(1);
}

if (!class_exists('Yii', false) && is_file($yiiRoot)) {
    require $yiiRoot;
}

// Test namespace autoloading (independent of Composer's dev autoload map).
spl_autoload_register(static function (string $class): void {
    $prefix = 'b10k\\componentguide\\tests\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

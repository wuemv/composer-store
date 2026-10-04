<?php

/**
 * Usage: php benchmarks/placement.php --store=DIR --cache=DIR [--target=DIR] [--rounds=3]
 *
 * Times ways of putting package trees into vendor/, on the store and Composer cache of a run.php run.
 */

declare(strict_types=1);

require __DIR__ . '/Placement.php';

use ComposerStore\Benchmarks\Placement;

spl_autoload_register(static function (string $class): void {
    $prefix = 'ComposerStore\\';
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (str_starts_with($class, $prefix) && is_file($file)) {
        require $file;
    }
});

$options = getopt('', ['store:', 'cache:', 'target:', 'rounds:']);
$store = $options['store'] ?? null;
$cache = $options['cache'] ?? null;
if (!is_string($store) || !is_string($cache)) {
    fwrite(STDERR, "Usage: php benchmarks/placement.php --store=DIR --cache=DIR [--target=DIR] [--rounds=3]\n");
    exit(1);
}
$target = is_string($options['target'] ?? null) ? $options['target'] : dirname($store) . '/placement';
$rounds = is_string($options['rounds'] ?? null) ? max(1, (int) $options['rounds']) : 3;

echo (new Placement($store, $cache, $target, $rounds))->run();

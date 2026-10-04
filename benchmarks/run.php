<?php

/**
 * Usage: php benchmarks/run.php [--work=DIR] [--runs=3] [--mode=auto ...] [--composer=PATH]
 *
 * Prints Markdown results on stdout and progress on stderr. See benchmarks/README.md.
 */

declare(strict_types=1);

require __DIR__ . '/Benchmark.php';

use ComposerStore\Benchmarks\Benchmark;

$options = getopt('', ['work:', 'runs:', 'mode:', 'composer:']);
$work = is_string($options['work'] ?? null) ? $options['work'] : sys_get_temp_dir() . '/composer-store-benchmark';
$runs = is_string($options['runs'] ?? null) ? max(1, (int) $options['runs']) : 3;
$modes = array_values(array_filter((array) ($options['mode'] ?? ['auto']), 'is_string'));
$composer = is_string($options['composer'] ?? null) ? $options['composer'] : null;
if ($composer === null) {
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_file($dir . '/composer')) {
            $composer = $dir . '/composer';
            break;
        }
    }
}
if ($composer === null) {
    fwrite(STDERR, "No composer on the PATH: use --composer=PATH\n");
    exit(1);
}

echo (new Benchmark(dirname(__DIR__), $work, $runs, $modes, $composer))->run();

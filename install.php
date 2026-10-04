<?php

/**
 * Installs composer-store globally, for every project of this user, by running the Composer commands the
 * README lists, then checks that Composer loads the plugin.
 *
 *     php install.php [--from=github|packagist|URL|DIR] [--composer=PATH] [--dry-run]
 *     curl -fsSL https://raw.githubusercontent.com/wuemv/composer-store/HEAD/install.php | php
 *
 * Written in syntax that PHP 7.2, the oldest PHP that runs Composer 2, still parses: an old PHP gets a
 * clear message rather than a parse error.
 */

// phpcs:disable PSR1.Files.SideEffects -- one file to download and run: it declares its helpers and runs

declare(strict_types=1);

const COMPOSER_STORE_PACKAGE = 'wuemv/composer-store';
const COMPOSER_STORE_GITHUB = 'https://github.com/wuemv/composer-store';

$arguments = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
exit(installComposerStore(array_values(array_filter(array_slice($arguments, 1), 'is_string'))));

/**
 * @param list<string> $args the command-line arguments
 *
 * @return int the exit status
 */
function installComposerStore(array $args): int
{
    $options = parseOptions($args);
    if ($options === null) {
        printUsage(STDERR);

        return 2;
    }
    if ($options['help']) {
        printUsage(STDOUT);

        return 0;
    }
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        return fail('composer-store needs PHP 8.1 or later, and this is PHP ' . PHP_VERSION . '.');
    }

    $composer = findComposer($options['composer']);
    if ($composer === null) {
        return fail(
            'Composer was not found. Install it (https://getcomposer.org/download/), '
            . 'or give its path with --composer=PATH.'
        );
    }
    $version = composerVersion($composer);
    if ($version === null) {
        return fail('Cannot run Composer (' . implode(' ', $composer) . ').');
    }
    if (version_compare($version, '2.0.0', '<')) {
        return fail("composer-store needs Composer 2, and this is Composer {$version}: run composer self-update.");
    }
    say("Composer {$version}, PHP " . PHP_VERSION . "\n\n");

    $steps = installSteps($options['from'], $version);
    if ($steps === null) {
        return fail('--from takes github, packagist, a repository URL or a directory, not ' . $options['from'] . '.');
    }
    foreach ($steps as $step) {
        if (runComposer($composer, $step, $options['dry-run']) !== 0) {
            return fail('That command failed, so the installation stopped.');
        }
    }
    if ($options['dry-run']) {
        say("\nDry run: nothing was changed.\n");

        return 0;
    }

    say("\nChecking that Composer loads the plugin:\n");
    if (runComposer($composer, ['store:status'], false) !== 0) {
        $hint = '';
        if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('COMPOSER_ALLOW_SUPERUSER') === false) {
            $hint = ' As root, Composer runs plugins only when COMPOSER_ALLOW_SUPERUSER=1 is set.';
        }

        return fail('composer-store is installed, but Composer did not load it.' . $hint);
    }

    say(
        "\ncomposer-store is installed. From now on, composer install, update, require and remove link "
        . "packages from the store above: the first project to install a version extracts it there, and "
        . "every other project links it.\n"
    );
    if (PHP_OS_FAMILY === 'Darwin' && !extension_loaded('ffi')) {
        say(
            "\nNote: PHP's FFI extension is not loaded, so the plugin clones packages with cp, which is "
            . "slower than with clonefile(2). See Requirements in the README.\n"
        );
    }

    return 0;
}

/**
 * @param list<string> $args
 *
 * @return array{from: string, composer: string|null, dry-run: bool, help: bool}|null null for an unknown option
 */
function parseOptions(array $args): ?array
{
    $options = ['from' => 'github', 'composer' => null, 'dry-run' => false, 'help' => false];
    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $options['dry-run'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif (strpos($arg, '--from=') === 0 && strlen($arg) > 7) {
            $options['from'] = substr($arg, 7);
        } elseif (strpos($arg, '--composer=') === 0 && strlen($arg) > 11) {
            $options['composer'] = substr($arg, 11);
        } else {
            fwrite(STDERR, "Unknown option: {$arg}\n\n");

            return null;
        }
    }

    return $options;
}

/**
 * The Composer arguments that install the plugin, as the README lists them.
 *
 * @return list<list<string>>|null null when $from is no source
 */
function installSteps(string $from, string $composerVersion): ?array
{
    $steps = [];
    $constraint = ':@dev';
    if ($from === 'packagist') {
        // A repository left by an install from GitHub would hide the releases on Packagist.
        $steps[] = ['global', 'config', '--unset', 'repositories.composer-store'];
        $constraint = '';
    } elseif ($from === 'github') {
        $steps[] = ['global', 'config', 'repositories.composer-store', 'vcs', COMPOSER_STORE_GITHUB];
    } elseif (preg_match('{^([a-z][a-z0-9+.-]*://|git@)}i', $from) === 1) {
        $steps[] = ['global', 'config', 'repositories.composer-store', 'vcs', $from];
    } elseif (is_dir($from)) {
        $steps[] = ['global', 'config', 'repositories.composer-store', 'path', (string) realpath($from)];
    } else {
        return null;
    }
    if (version_compare($composerVersion, '2.2.0', '>=')) {
        // Since Composer 2.2, a plugin runs only once it is allowed.
        $steps[] = ['global', 'config', 'allow-plugins.' . COMPOSER_STORE_PACKAGE, 'true'];
    }
    $steps[] = ['global', 'require', '--no-interaction', COMPOSER_STORE_PACKAGE . $constraint];

    return $steps;
}

/**
 * How to run Composer: the given command or file, or `composer` on the PATH. A PHP script or phar runs
 * with this PHP, which also covers Windows, where composer.bat sits next to composer.phar.
 *
 * @return list<string>|null
 */
function findComposer(?string $given): ?array
{
    $windows = PHP_OS_FAMILY === 'Windows';
    if ($given !== null) {
        $file = is_file($given) ? $given : findInPath([$given]);
    } else {
        $file = findInPath($windows ? ['composer.phar', 'composer.bat', 'composer'] : ['composer', 'composer.phar']);
    }
    if ($file === null) {
        return null;
    }
    if ($windows && preg_match('{\.(bat|cmd)$}i', $file) === 1 && is_file(dirname($file) . '/composer.phar')) {
        $file = dirname($file) . '/composer.phar';
    }
    if (isPhpScript($file)) {
        return [PHP_BINARY, $file];
    }

    return $windows && preg_match('{\.(bat|cmd)$}i', $file) === 1 ? ['cmd', '/c', $file] : [$file];
}

/**
 * @param list<string> $names
 */
function findInPath(array $names): ?string
{
    $path = getenv('PATH');
    foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $dir) {
        foreach ($names as $name) {
            if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                return $dir . DIRECTORY_SEPARATOR . $name;
            }
        }
    }

    return null;
}

function isPhpScript(string $file): bool
{
    if (preg_match('{\.phar$}i', $file) === 1) {
        return true;
    }
    $head = (string) @file_get_contents($file, false, null, 0, 64);
    $firstLine = explode("\n", $head)[0];

    return strpos($head, '<?php') === 0 || (strpos($firstLine, '#!') === 0 && strpos($firstLine, 'php') !== false);
}

/**
 * @param list<string> $composer
 */
function composerVersion(array $composer): ?string
{
    $output = tmpfile();
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = $output === false ? false : @proc_open(
        array_merge($composer, ['--version', '--no-ansi']),
        [0 => ['file', $null, 'r'], 1 => $output, 2 => ['file', $null, 'w']],
        $pipes
    );
    if ($output === false || !is_resource($process)) {
        return null;
    }
    $status = proc_close($process);
    rewind($output);
    $text = (string) stream_get_contents($output);
    fclose($output);
    if ($status !== 0 || preg_match('{Composer (?:version )?(\d+\.\d+\.\d+)}', $text, $match) !== 1) {
        return null;
    }

    return $match[1];
}

/**
 * Shows the command as it would be typed, then runs it with this script's input and output. Output that
 * goes to a file goes through a temp file first: before proc_open() passes a file on, PHP moves its offset
 * back to the end of this script's own writes, so each Composer would write over the one before.
 *
 * @param list<string> $composer
 * @param list<string> $args
 *
 * @return int Composer's exit status
 */
function runComposer(array $composer, array $args, bool $dryRun): int
{
    say('> composer ' . implode(' ', $args) . "\n");
    if ($dryRun) {
        return 0;
    }
    $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
    $relays = [];
    foreach ([1, 2] as $fd) {
        $temp = stream_get_meta_data($descriptors[$fd])['seekable'] ? tmpfile() : false;
        if ($temp !== false) {
            $relays[] = [$temp, $descriptors[$fd]];
            $descriptors[$fd] = $temp;
        }
    }
    $process = @proc_open(array_merge($composer, $args), $descriptors, $pipes);
    $status = is_resource($process) ? proc_close($process) : 1;
    foreach ($relays as list($temp, $stream)) {
        rewind($temp);
        stream_copy_to_stream($temp, $stream);
        fclose($temp);
    }

    return $status;
}

/**
 * @param resource $stream
 */
function printUsage($stream): void
{
    fwrite($stream, implode("\n", [
        'Installs composer-store globally, for every project of this user.',
        '',
        'Usage: php install.php [options]',
        '  --from=SOURCE    where to install it from: github (the default, until it is on Packagist),',
        '                   packagist, a repository URL, or a directory with a clone',
        '  --composer=PATH  the Composer to run (default: composer on the PATH)',
        '  --dry-run        print the commands without running them',
        '  --help           show this help',
        '',
    ]));
}

function say(string $text): void
{
    fwrite(STDOUT, $text);
}

/**
 * @return int the exit status for a failure
 */
function fail(string $message): int
{
    fwrite(STDERR, "\nError: {$message}\n");

    return 1;
}

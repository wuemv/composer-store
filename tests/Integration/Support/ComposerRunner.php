<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration\Support;

use ComposerStore\Tests\Support\Process;
use ComposerStore\Tests\Support\ProcessResult;
use ComposerStore\Tests\Support\RunningProcess;

/**
 * Runs the Composer binary under test: $COMPOSER_STORE_TEST_COMPOSER, or `composer` from the PATH.
 */
final class ComposerRunner
{
    /**
     * @param list<string> $command
     */
    private function __construct(private readonly array $command)
    {
    }

    public static function fromEnvironment(): self
    {
        $binary = getenv('COMPOSER_STORE_TEST_COMPOSER');
        if ($binary === false || $binary === '') {
            $binary = self::findInPath('composer');
        }
        // Composer runs inside the test projects, so a relative path would point nowhere.
        $binary = realpath($binary) ?: throw new \RuntimeException("Composer binary {$binary} not found");

        return new self(self::isPhpScript($binary) ? [PHP_BINARY, $binary] : [$binary]);
    }

    /**
     * The Composer script or binary that runs, e.g. for install.php's --composer.
     */
    public function binary(): string
    {
        return $this->command[count($this->command) - 1];
    }

    /**
     * Runs Composer with a clean environment: no COMPOSER* variable from the outside leaks in, and
     * unless $network is set, no proxy settings either (fixture tests only read local files).
     *
     * @param list<string>          $args
     * @param array<string, string> $env  COMPOSER_HOME, COMPOSER_STORE_DIR and so on
     */
    public function run(string $cwd, array $args, array $env, bool $network = false): ProcessResult
    {
        return $this->start($cwd, $args, $env, $network)->wait();
    }

    /**
     * Like run(), without waiting for Composer to finish.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public function start(string $cwd, array $args, array $env, bool $network = false): RunningProcess
    {
        $env += [
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER_DISABLE_XDEBUG_WARN' => '1',
        ];
        $inherited = Process::environmentWithoutComposer();
        if (!$network) {
            $inherited = array_filter(
                $inherited,
                static fn (string $name): bool => !str_ends_with(strtolower($name), '_proxy'),
                ARRAY_FILTER_USE_KEY
            );
        }
        $command = [...$this->command, ...$args, '--no-ansi'];

        return Process::start($command, $cwd, $env + $inherited);
    }

    private static function findInPath(string $name): string
    {
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $dir) {
            foreach ([$name, $name . '.phar'] as $candidate) {
                if ($dir !== '' && is_file($dir . '/' . $candidate)) {
                    return $dir . '/' . $candidate;
                }
            }
        }

        throw new \RuntimeException('composer is not on the PATH; set COMPOSER_STORE_TEST_COMPOSER');
    }

    private static function isPhpScript(string $file): bool
    {
        if (str_ends_with($file, '.phar')) {
            return true;
        }
        $head = (string) @file_get_contents($file, false, null, 0, 64);
        $firstLine = explode("\n", $head)[0];

        $shebang = str_starts_with($firstLine, '#!') && str_contains($firstLine, 'php');

        return $shebang || str_starts_with($head, '<?php');
    }
}

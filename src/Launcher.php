<?php

declare(strict_types=1);

namespace ComposerStore;

/**
 * The composer-store command a global install puts in Composer's vendor/bin, next to `laravel` and
 * `valet`: `composer-store dashboard` runs `composer store:dashboard`, and so on, with the same options.
 *
 * On macOS it runs Composer with a PHP that has the FFI extension when the one it runs on lacks it,
 * since the monitor needs FFI to tell clones from copies: the first `php`, `php84` or `php8.4` on the
 * PATH, of this PHP's version, that has it. COMPOSER_STORE_PHP and COMPOSER_STORE_COMPOSER name the
 * PHP and the Composer to run instead.
 */
final class Launcher
{
    private const COMMANDS = [
        'dashboard' => 'live charts of the disk space the projects in a directory take',
        'monitor' => 'the same, in the terminal',
        'status' => 'where the store is, what it holds and the disk space it saves',
        'verify' => 'checks the store for files that changed',
        'prune' => 'lists the versions no project uses; --force deletes them',
    ];

    /**
     * @param ?string                $path   the directories to search, PATH by default
     * @param ?\Closure(string): bool $hasFfi whether a PHP has FFI; by default asked by running it
     */
    public function __construct(
        private readonly string $os = PHP_OS_FAMILY,
        private readonly ?string $path = null,
        private readonly ?\Closure $hasFfi = null,
    ) {
    }

    /**
     * Runs Composer's store command in this terminal, so that it can ask and draw as usual.
     *
     * @param list<string> $args   what followed the command's name
     * @param resource     $stdout where the help goes
     * @param resource     $stderr where the launcher's own errors go
     *
     * @return int the exit status: Composer's
     */
    public function run(array $args, $stdout = STDOUT, $stderr = STDERR): int
    {
        $command = $args[0] ?? 'help';
        if (!isset(self::COMMANDS[$command])) {
            $help = in_array($command, ['help', '--help', '-h'], true);
            fwrite($help ? $stdout : $stderr, ($help ? '' : "Unknown command: {$command}\n\n") . $this->usage());

            return $help ? 0 : 1;
        }
        $composer = $this->composer();
        if ($composer === null) {
            fwrite($stderr, "composer-store: Composer is not on the PATH. Set COMPOSER_STORE_COMPOSER to it.\n");

            return 1;
        }
        $line = [...$composer, 'store:' . $command, ...array_slice($args, 1)];
        $process = proc_open($line, [STDIN, STDOUT, STDERR], $pipes);

        return is_resource($process) ? proc_close($process) : 1;
    }

    /**
     * How to run Composer: with this launcher's choice of PHP when it is a PHP script or phar, as it is
     * on its own otherwise. Null when there is none.
     *
     * @return ?list<string>
     */
    public function composer(): ?array
    {
        $file = self::env('COMPOSER_STORE_COMPOSER') ?? $this->find($this->os === 'Windows'
            ? ['composer.phar', 'composer.bat', 'composer']
            : ['composer', 'composer.phar']);
        if ($file === null) {
            return null;
        }
        $phar = dirname($file) . '/composer.phar';
        if ($this->os === 'Windows' && preg_match('{\.(bat|cmd)$}i', $file) === 1 && is_file($phar)) {
            $file = $phar;
        }
        if (self::isPhpScript($file)) {
            return [$this->php(), $file];
        }

        return $this->os === 'Windows' && preg_match('{\.(bat|cmd)$}i', $file) === 1 ? ['cmd', '/c', $file] : [$file];
    }

    /**
     * The PHP to run Composer with: COMPOSER_STORE_PHP; else this one, unless this is macOS and it lacks
     * FFI while a PHP of this version on the PATH has it.
     */
    public function php(): string
    {
        $given = self::env('COMPOSER_STORE_PHP');
        if ($given !== null) {
            return $given;
        }
        if ($this->os !== 'Darwin' || $this->ffi(PHP_BINARY)) {
            return PHP_BINARY;
        }
        $version = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        foreach ($this->dirs() as $dir) {
            foreach (['php', 'php' . str_replace('.', '', $version), 'php' . $version] as $name) {
                $php = $dir . '/' . $name;
                $other = is_file($php) && is_executable($php) && realpath($php) !== realpath(PHP_BINARY);
                if ($other && $this->ffi($php)) {
                    return $php;
                }
            }
        }

        return PHP_BINARY;
    }

    private function usage(): string
    {
        $lines = ["composer-store runs the composer-store plugin's commands:", ''];
        foreach (self::COMMANDS as $command => $description) {
            $lines[] = sprintf('  composer-store %-10s %s', $command, $description);
        }
        $lines[] = '';
        $lines[] = 'Each one is composer store:<command>, with its options: composer-store dashboard --help.';

        return implode("\n", $lines) . "\n";
    }

    private function ffi(string $php): bool
    {
        if ($this->hasFfi !== null) {
            return ($this->hasFfi)($php);
        }
        if ($php === PHP_BINARY) {
            return self::ffiAllowed(extension_loaded('ffi'), ini_get('ffi.enable'));
        }
        // The same question, asked of the other PHP. ffi.enable is "preload" by default: allowed on the
        // command line.
        $check = 'echo extension_loaded("ffi") && !in_array(strtolower((string) ini_get("ffi.enable")),'
            . ' ["0", "false", "off"], true) ? 1 : 0;';
        $process = proc_open([$php, '-r', $check], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        $answer = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $answer === '1';
    }

    private static function ffiAllowed(bool $loaded, string|false $enable): bool
    {
        return $loaded && !in_array(strtolower((string) $enable), ['0', 'false', 'off'], true);
    }

    /**
     * @param list<string> $names
     */
    private function find(array $names): ?string
    {
        foreach ($this->dirs() as $dir) {
            foreach ($names as $name) {
                if (is_file($dir . '/' . $name)) {
                    return $dir . '/' . $name;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function dirs(): array
    {
        $path = $this->path ?? self::env('PATH') ?? '';

        return array_values(array_filter(
            array_map(static fn (string $dir): string => rtrim($dir, '/\\'), explode(PATH_SEPARATOR, $path)),
            static fn (string $dir): bool => $dir !== ''
        ));
    }

    private static function isPhpScript(string $file): bool
    {
        if (str_ends_with(strtolower($file), '.phar')) {
            return true;
        }
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return false;
        }
        $start = (string) fread($handle, 256);
        fclose($handle);

        return str_starts_with($start, '<?php') || preg_match('{^#!.*\bphp\b}', $start) === 1;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}

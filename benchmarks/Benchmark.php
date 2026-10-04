<?php

declare(strict_types=1);

namespace ComposerStore\Benchmarks;

/**
 * Installs the projects in benchmarks/projects without the plugin, then with it in each given mode, from
 * an empty store and from a warm one. Measures the time of each install and the disk space they take.
 *
 * Every measured install runs offline (COMPOSER_DISABLE_NETWORK) from a Composer cache that a warm-up
 * filled, and with --no-scripts: no downloads, no Laravel package discovery. Each scenario runs twice,
 * once as a whole install and once with --no-autoloader, since generating the autoloader takes the
 * same time with or without the plugin. Disk space is the drop in the filesystem's free space, which,
 * unlike du, counts reflinks right.
 */
final class Benchmark
{
    private const INSTALL = ['install', '--no-scripts', '--no-progress', '--no-interaction', '--no-ansi'];

    /** @var list<string> */
    private readonly array $composer;

    /** Variables that would move files somewhere else, which the benchmark sets itself. */
    private const DROPPED_ENV = [
        'COMPOSER', 'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'COMPOSER_VENDOR_DIR', 'COMPOSER_BIN_DIR',
        'COMPOSER_STORE_DIR', 'COMPOSER_DISABLE_NETWORK',
    ];

    /** @var array<string, string> */
    private readonly array $env;

    /** @var array<string, int> project name => number of packages */
    private array $projects = [];

    /** @var array<string, array<string, array<string, list<float>>>> variant => scenario => project => seconds */
    private array $times = [];

    /** @var array<string, list<int>> scenario => bytes the installs of all projects took, per run */
    private array $disk = [];

    /** @var array<string, list<int>> mode => store size after the empty-store runs */
    private array $stores = [];

    /** @var array<string, string> mode => the method the plugin used */
    private array $methods = [];

    /**
     * @param list<string> $modes values of extra.composer-store.mode to measure
     */
    public function __construct(
        private readonly string $repo,
        private readonly string $work,
        private readonly int $runs,
        private readonly array $modes,
        string $composer,
    ) {
        $this->composer = self::isPhpScript($composer) ? [PHP_BINARY, $composer] : [$composer];
        $env = array_diff_key(getenv(), array_flip(self::DROPPED_ENV));
        $this->env = [
            'COMPOSER_CACHE_DIR' => $work . '/cache',
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_NO_AUDIT' => '1',
            'COMPOSER_DISABLE_XDEBUG_WARN' => '1',
        ] + $env;
    }

    /**
     * @return string the results, as Markdown
     */
    public function run(): string
    {
        $this->prepare();
        $this->warmUp();

        for ($run = 1; $run <= $this->runs; $run++) {
            foreach (['install' => [], 'files' => ['--no-autoloader']] as $variant => $args) {
                $step = sprintf('Run %d of %d, %s', $run, $this->runs, $variant);
                self::progress($step . ': no plugin');
                $this->measure($variant, 'plain', $args, $this->home('plain'));
                foreach ($this->modes as $mode) {
                    $store = $this->work . '/store-' . $mode;
                    self::remove($store);
                    self::progress(sprintf('%s: mode %s, empty store', $step, $mode));
                    $this->measure($variant, 'empty-' . $mode, $args, $this->home($mode), $store);
                    if ($variant === 'install') {
                        $this->stores[$mode][] = $this->diskUsage($store);
                        $this->methods[$mode] = $this->method($store);
                    }
                    self::progress(sprintf('%s: mode %s, warm store', $step, $mode));
                    $this->measure($variant, 'warm-' . $mode, $args, $this->home($mode), $store);
                }
            }
        }

        return $this->report();
    }

    /**
     * Installs every project, after removing every vendor/, and records the time of each install and
     * the disk space they took together.
     *
     * @param list<string> $args added to `composer install`
     */
    private function measure(string $variant, string $scenario, array $args, string $home, ?string $store = null): void
    {
        foreach (array_keys($this->projects) as $project) {
            self::remove($this->work . '/projects/' . $project . '/vendor');
        }
        clearstatcache();
        $before = self::usedSpace($this->work);
        $env = ['COMPOSER_HOME' => $home, 'COMPOSER_DISABLE_NETWORK' => '1'];
        if ($store !== null) {
            $env['COMPOSER_STORE_DIR'] = $store;
        }
        foreach (array_keys($this->projects) as $project) {
            $start = hrtime(true);
            $this->composer($this->work . '/projects/' . $project, [...self::INSTALL, ...$args], $env);
            $this->times[$variant][$scenario][$project][] = (hrtime(true) - $start) / 1e9;
        }
        clearstatcache();
        if ($variant === 'install') {
            $this->disk[$scenario][] = self::usedSpace($this->work) - $before;
        }
    }

    /**
     * Copies the projects and the plugin into the work directory, and makes a Composer home without
     * the plugin, and one with it for each mode.
     */
    private function prepare(): void
    {
        // Everything but the Composer cache, so another run downloads nothing.
        foreach (glob($this->work . '/*') ?: [] as $path) {
            if ($path !== $this->work . '/cache') {
                self::remove($path);
            }
        }
        self::makeDir($this->work);
        foreach (glob($this->repo . '/benchmarks/projects/*/composer.lock') ?: [] as $lock) {
            $name = basename(dirname($lock));
            $target = $this->work . '/projects/' . $name;
            self::makeDir($target);
            copy(dirname($lock) . '/composer.json', $target . '/composer.json');
            copy($lock, $target . '/composer.lock');
            $data = json_decode((string) file_get_contents($lock), true);
            $this->projects[$name] = 0;
            foreach (['packages', 'packages-dev'] as $key) {
                $this->projects[$name] += is_array($data) && is_array($data[$key] ?? null) ? count($data[$key]) : 0;
            }
        }
        if ($this->projects === []) {
            throw new \RuntimeException('No projects in ' . $this->repo . '/benchmarks/projects');
        }

        // The plugin as a path repository can install it: composer.json with a version, and src/.
        $manifest = json_decode((string) file_get_contents($this->repo . '/composer.json'), true);
        $keep = array_flip(['name', 'description', 'type', 'require', 'autoload', 'extra']);
        $plugin = array_intersect_key(is_array($manifest) ? $manifest : [], $keep) + ['version' => '1.0.0'];
        $name = is_string($plugin['name'] ?? null) ? $plugin['name'] : throw new \RuntimeException('No plugin name');
        self::writeJson($this->work . '/plugin/composer.json', $plugin);
        $this->exec(['cp', '-R', $this->repo . '/src', $this->work . '/plugin/src'], $this->work);

        self::makeDir($this->home('plain'));
        foreach ($this->modes as $mode) {
            self::writeJson($this->home($mode) . '/composer.json', [
                'repositories' => [
                    ['type' => 'path', 'url' => $this->work . '/plugin', 'options' => ['symlink' => false]],
                    ['packagist.org' => false],
                ],
                'require' => [$name => '1.0.0'],
                'config' => ['allow-plugins' => [$name => true]],
                'extra' => ['composer-store' => ['mode' => $mode]],
            ]);
            $this->composer($this->home($mode), ['install', '--no-interaction', '--no-ansi'], [
                'COMPOSER_HOME' => $this->home($mode),
            ]);
        }
    }

    /**
     * Fills the Composer cache with every project's dists, through your own Composer home (its auth
     * and plugins) and the network.
     */
    private function warmUp(): void
    {
        $home = getenv('COMPOSER_HOME');
        $env = is_string($home) && $home !== '' ? ['COMPOSER_HOME' => $home] : [];
        // A store of its own, in case your Composer home has composer-store.
        $env['COMPOSER_STORE_DIR'] = $this->work . '/warm-up-store';
        foreach (array_keys($this->projects) as $project) {
            self::progress('Downloading the packages of ' . $project);
            $dir = $this->work . '/projects/' . $project;
            for ($attempt = 1;; $attempt++) {
                try {
                    $this->composer($dir, self::INSTALL, $env);
                    break;
                } catch (\RuntimeException $e) {
                    if ($attempt === 3) {
                        throw $e;
                    }
                    self::progress('Retrying after: ' . trim(substr($e->getMessage(), -300)));
                }
            }
            self::remove($dir . '/vendor');
        }
        self::remove($this->work . '/warm-up-store');
    }

    private function report(): string
    {
        $names = ['plain' => 'No plugin'];
        foreach ($this->modes as $mode) {
            $names[$mode] = ucfirst($this->methods[$mode]) . ($mode === 'auto' ? ' (auto)' : '');
        }
        $scenarios = ['plain' => $names['plain']];
        foreach ($this->modes as $mode) {
            $scenarios['empty-' . $mode] = $names[$mode] . ', empty store';
            $scenarios['warm-' . $mode] = $names[$mode] . ', warm store';
        }
        $version = strtok($this->composer($this->work, ['--version', '--no-ansi'], [
            'COMPOSER_HOME' => $this->home('plain'),
        ]), "\n");

        $lines = [sprintf(
            '%s %s %s, PHP %s%s, %s. Median of %d %s.',
            php_uname('s'),
            php_uname('r'),
            php_uname('m'),
            PHP_VERSION,
            // On macOS, FFI decides how the plugin clones: clonefile(2) with it, cp without.
            extension_loaded('ffi') ? ' with FFI' : '',
            trim((string) $version),
            $this->runs,
            $this->runs === 1 ? 'run' : 'runs'
        )];
        $titles = ['install' => 'Install time', 'files' => 'Install time, --no-autoloader'];
        foreach ($titles as $variant => $title) {
            array_push(
                $lines,
                '',
                '| ' . $title . ' | Packages | ' . implode(' | ', $scenarios) . ' |',
                '|---|---:|' . str_repeat('---:|', count($scenarios)),
            );
            foreach ($this->projects as $project => $packages) {
                $cells = [];
                foreach (array_keys($scenarios) as $scenario) {
                    $cells[] = sprintf('%.2f s', self::median($this->times[$variant][$scenario][$project]));
                }
                $lines[] = sprintf('| %s | %d | %s |', $project, $packages, implode(' | ', $cells));
            }
            $totals = [];
            foreach (array_keys($scenarios) as $scenario) {
                $sums = [];
                for ($run = 0; $run < $this->runs; $run++) {
                    $sums[] = array_sum(array_column($this->times[$variant][$scenario], $run));
                }
                $totals[] = sprintf('**%.2f s**', self::median($sums));
            }
            $lines[] = sprintf(
                '| **All %d** | %d | %s |',
                count($this->projects),
                array_sum($this->projects),
                implode(' | ', $totals)
            );
        }

        // The store's du cannot be subtracted from the free-space drop: filesystems such as Btrfs keep
        // small files inline, where du counts whole blocks.
        $plain = (int) self::median($this->disk['plain']);
        $cells = [self::size($plain)];
        $storeSizes = [];
        foreach ($this->modes as $mode) {
            $used = (int) self::median($this->disk['empty-' . $mode]);
            $saved = $plain > 0 ? 100 * ($plain - $used) / $plain : 0;
            $cells[] = sprintf('%s (−%.0f%%)', self::size($used), $saved);
            $store = (int) self::median($this->stores[$mode]);
            $storeSizes[] = sprintf('%s with %s', self::size($store), lcfirst($names[$mode]));
        }
        array_push(
            $lines,
            '',
            '| Disk space | ' . implode(' | ', $names) . ' |',
            '|---|' . str_repeat('---:|', count($names)),
            sprintf('| %d projects | %s |', count($this->projects), implode(' | ', $cells)),
            '',
            'Disk space is the drop in free space while installing all projects into an empty store, so it includes '
                . 'the store. The store alone, per du: ' . implode(', ', $storeSizes) . '.',
        );

        return implode("\n", $lines) . "\n";
    }

    private function home(string $mode): string
    {
        return $this->work . '/home-' . $mode;
    }

    /**
     * The method the plugin used, from the store's projects.json.
     */
    private function method(string $store): string
    {
        $registry = json_decode((string) @file_get_contents($store . '/projects.json'), true);
        $projects = is_array($registry) && is_array($registry['projects'] ?? null) ? $registry['projects'] : [];
        $first = reset($projects);
        $method = is_array($first) ? $first['method'] ?? null : null;

        return match ($method) {
            'reflink' => 'reflinks',
            'hardlink' => 'hard links',
            default => 'no store',
        };
    }

    /**
     * Space used on the filesystem holding $dir, in bytes.
     */
    private static function usedSpace(string $dir): int
    {
        return (int) (disk_total_space($dir) - disk_free_space($dir));
    }

    private function diskUsage(string $dir): int
    {
        return (int) $this->exec(['du', '-sk', $dir], $this->work) * 1024;
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    private function composer(string $cwd, array $args, array $env): string
    {
        return $this->exec([...$this->composer, ...$args], $cwd, $env);
    }

    /**
     * Runs a command without a shell and returns its output, or throws with the output when it fails.
     *
     * @param list<string>          $command
     * @param array<string, string> $env     variables to set on top of the base environment
     */
    private function exec(array $command, string $cwd, array $env = []): string
    {
        $log = $this->work . '/command.log';
        file_put_contents($log, '');
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']];
        $process = proc_open($command, $descriptors, $pipes, $cwd, $env + $this->env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot run ' . $command[0]);
        }
        $status = proc_close($process);
        $output = (string) file_get_contents($log);
        if ($status !== 0) {
            $message = sprintf("%s failed with %d in %s:\n%s", implode(' ', $command), $status, $cwd, $output);
            throw new \RuntimeException($message);
        }

        return $output;
    }

    /**
     * @param list<float|int> $values
     */
    private static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private static function size(int $bytes): string
    {
        return sprintf('%.0f MiB', $bytes / (1 << 20));
    }

    private static function isPhpScript(string $file): bool
    {
        $start = (string) @file_get_contents($file, false, null, 0, 64);

        return str_starts_with($start, '<?php') || preg_match('{^#!.*\bphp\b}', $start) === 1;
    }

    /**
     * @param array<mixed> $data
     */
    private static function writeJson(string $file, array $data): void
    {
        self::makeDir(dirname($file));
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private static function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
    }

    /**
     * Removes a tree with rm, which copes with read-only files and is quick on big trees.
     */
    private static function remove(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $process = proc_open(['rm', '-rf', $path], [], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0 || file_exists($path)) {
            throw new \RuntimeException('Cannot remove ' . $path);
        }
    }

    private static function progress(string $message): void
    {
        fwrite(STDERR, $message . "\n");
    }
}

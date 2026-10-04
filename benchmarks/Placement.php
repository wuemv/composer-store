<?php

declare(strict_types=1);

namespace ComposerStore\Benchmarks;

use ComposerStore\Link\Cloner;
use ComposerStore\Link\Linker;

/**
 * Times ways of putting package trees into vendor/: the store's ways, one package after the other and
 * in parallel, and what Composer does without the plugin (unzip). Works on the store and the Composer
 * cache that a run.php run leaves in its work directory.
 */
final class Placement
{
    /** @var list<array{files: string, zip: string}> */
    private array $entries = [];

    private int $files = 0;

    public function __construct(
        private readonly string $store,
        private readonly string $cache,
        private readonly string $target,
        private readonly int $rounds,
    ) {
    }

    /**
     * Writes the results as Markdown, a row as soon as it is measured.
     *
     * @param \Closure(string): void $write gets one line at a time
     */
    public function run(\Closure $write): void
    {
        $this->load();
        $lines = [
            sprintf(
                '%s %s %s, PHP %s%s. %d packages, %d files, median of %d rounds.',
                php_uname('s'),
                php_uname('r'),
                php_uname('m'),
                PHP_VERSION,
                extension_loaded('ffi') ? ' with FFI' : '',
                count($this->entries),
                $this->files,
                $this->rounds
            ),
            '',
            '| Method | Time | Per package |',
            '|---|---:|---:|',
        ];
        foreach ($lines as $line) {
            $write($line);
        }
        foreach ($this->methods() as $name => $method) {
            $times = [];
            for ($round = 0; $round < $this->rounds; $round++) {
                self::exec(['rm', '-rf', $this->target]);
                mkdir($this->target, 0777, true);
                $start = hrtime(true);
                $method($this->target);
                $times[] = (hrtime(true) - $start) / 1e9;
            }
            sort($times);
            $median = $times[intdiv(count($times), 2)];
            $write(sprintf('| %s | %.2f s | %.1f ms |', $name, $median, 1000 * $median / count($this->entries)));
        }
        self::exec(['rm', '-rf', $this->target]);
    }

    /**
     * Every store entry, with the archive Composer would extract instead.
     */
    private function load(): void
    {
        foreach (glob($this->store . '/packages/*/*/*/.store-meta.json') ?: [] as $metaFile) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            $name = is_array($meta) && is_string($meta['name'] ?? null) ? $meta['name'] : '';
            $dist = is_array($meta) && is_array($meta['dist'] ?? null) ? $meta['dist'] : [];
            $url = is_string($dist['url'] ?? null) ? $dist['url'] : '';
            $type = is_string($dist['type'] ?? null) ? $dist['type'] : 'zip';
            // Composer's cache key: the package name and a hash of the dist URL.
            $zip = sprintf('%s/%s/%s.%s', $this->cache, $name, sha1($url), $type);
            if (!is_file($zip)) {
                throw new \RuntimeException('No archive for ' . $name . ' in ' . $this->cache);
            }
            $files = dirname($metaFile) . '/files';
            $this->entries[] = ['files' => $files, 'zip' => $zip];
            $count = 0;
            $iterator = new \RecursiveDirectoryIterator($files, \FilesystemIterator::SKIP_DOTS);
            foreach (new \RecursiveIteratorIterator($iterator) as $info) {
                $count += $info instanceof \SplFileInfo && $info->isFile() ? 1 : 0;
            }
            $this->files += $count;
        }
        if ($this->entries === []) {
            throw new \RuntimeException('No entries in ' . $this->store);
        }
    }

    /**
     * @return array<string, \Closure(string): void> each fills the given empty directory
     */
    private function methods(): array
    {
        $entries = $this->entries;
        $methods = [
            'PHP link() per file (hard links)' => static function (string $to) use ($entries): void {
                $linker = new Linker();
                foreach ($entries as $index => $entry) {
                    $linker->link($entry['files'], $to . '/' . $index);
                }
            },
            'PHP copy() per file' => static function (string $to) use ($entries): void {
                $linker = new Linker();
                foreach ($entries as $index => $entry) {
                    $linker->copy($entry['files'], $to . '/' . $index);
                }
            },
        ];
        foreach ([1, 10] as $parallel) {
            $methods["unzip per package, {$parallel} at a time (Composer)"] = $this->perPackage(
                $parallel,
                static fn (array $entry, string $to): array => [['unzip', '-qq', $entry['zip'], '-d', $to], null]
            );
        }
        $darwin = PHP_OS_FAMILY === 'Darwin';
        foreach ([1, 10] as $parallel) {
            $name = sprintf('%s per package, %d at a time (hard links)', $darwin ? 'pax -rwl' : 'cp -al', $parallel);
            $methods[$name] = $this->perPackage(
                $parallel,
                static function (array $entry, string $to) use ($darwin): array {
                    if (!$darwin) {
                        return [['cp', '-al', $entry['files'], $to], null];
                    }
                    mkdir($to);

                    return [['pax', '-rw', '-l', '.', $to], $entry['files']];
                }
            );
        }

        @mkdir($this->target, 0777, true);
        if ((new Cloner())->isSupported($this->target)) {
            $clone = $darwin ? ['cp', '-c', '-R', '-p'] : ['cp', '-R', '-T', '--reflink=always'];
            foreach ([1, 4, 10] as $parallel) {
                $methods["cp clone per package, {$parallel} at a time (reflinks)"] = $this->perPackage(
                    $parallel,
                    static fn (array $entry, string $to): array => [[...$clone, $entry['files'], $to], null]
                );
            }
            if ($darwin && extension_loaded('ffi')) {
                $ffi = \FFI::cdef('int clonefile(const char *src, const char *dst, uint32_t flags);');
                $methods['clonefile() per package, through FFI (reflinks)'] = static function (string $to) use (
                    $entries,
                    $ffi,
                ): void {
                    foreach ($entries as $index => $entry) {
                        if ($ffi->clonefile($entry['files'], $to . '/' . $index, 0) !== 0) {
                            throw new \RuntimeException('clonefile() failed for ' . $entry['files']);
                        }
                    }
                };
            }
        }

        return $methods;
    }

    /**
     * A method that runs one command per package, $parallel at a time.
     *
     * @param \Closure(array{files: string, zip: string}, string): array{list<string>, string|null} $command
     *
     * @return \Closure(string): void
     */
    private function perPackage(int $parallel, \Closure $command): \Closure
    {
        $entries = $this->entries;

        return static function (string $to) use ($entries, $parallel, $command): void {
            $jobs = [];
            foreach ($entries as $index => $entry) {
                $jobs[] = $command($entry, $to . '/' . $index);
            }
            self::runJobs($jobs, $parallel);
        };
    }

    /**
     * Runs commands, at most $parallel at a time, and fails on the first error.
     *
     * @param list<array{list<string>, string|null}> $jobs command and working directory
     */
    private static function runJobs(array $jobs, int $parallel): void
    {
        $running = [];
        $null = ['file', '/dev/null', 'w'];
        while ($jobs !== [] || $running !== []) {
            while ($jobs !== [] && count($running) < $parallel) {
                [$command, $cwd] = array_shift($jobs);
                $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, $cwd);
                if (!is_resource($process)) {
                    throw new \RuntimeException('Cannot run ' . implode(' ', $command));
                }
                $running[] = [$process, $command];
            }
            foreach ($running as $index => [$process, $command]) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    proc_close($process);
                    unset($running[$index]);
                    if ($status['exitcode'] !== 0) {
                        throw new \RuntimeException(implode(' ', $command) . ' failed with ' . $status['exitcode']);
                    }
                }
            }
            usleep(500);
        }
    }

    /**
     * @param list<string> $command
     */
    private static function exec(array $command): void
    {
        $process = proc_open($command, [], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException(implode(' ', $command) . ' failed');
        }
    }
}

<?php

declare(strict_types=1);

namespace ComposerStore\Store;

use ComposerStore\Link\Method;

/**
 * `projects.json`: the projects that have linked from the store, with their vendor directory and the
 * method of their last install. Pruning reads their `vendor/composer/installed.json` to know which
 * entries are still in use: clones, unlike hard links, do not show in the store's link counts.
 *
 * Every change is a read-modify-write under its own short lock (not the store lock, which installs
 * hold shared), and the file is replaced with an atomic rename.
 *
 * @phpstan-type Record array{vendor-dir: string, last-install: string, method: string}
 */
final class ProjectRegistry
{
    private const LOCK_TIMEOUT = 10.0;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return array<string, array{vendor-dir: string, last-install: string, method: ?Method}> by project directory
     */
    public function projects(): array
    {
        return array_map(
            static fn (array $record): array => [
                'vendor-dir' => $record['vendor-dir'],
                'last-install' => $record['last-install'],
                'method' => Method::tryFrom($record['method']),
            ],
            $this->records()
        );
    }

    public function register(string $projectDir, string $vendorDir, Method $method): void
    {
        $this->update(static function (array $records) use ($projectDir, $vendorDir, $method): array {
            $records[$projectDir] = [
                'vendor-dir' => $vendorDir,
                'last-install' => gmdate(DATE_ATOM),
                'method' => $method->value,
            ];

            return $records;
        });
    }

    /**
     * @param list<string> $projectDirs
     */
    public function forget(array $projectDirs): void
    {
        $this->update(static fn (array $projects): array => array_diff_key($projects, array_flip($projectDirs)));
    }

    /**
     * The file's valid records. `method` is empty in records from older plugin versions.
     *
     * @return array<string, Record>
     */
    private function records(): array
    {
        $data = json_decode((string) @file_get_contents($this->file), true);
        $entries = is_array($data) && is_array($data['projects'] ?? null) ? $data['projects'] : [];
        $records = [];
        foreach ($entries as $dir => $details) {
            if (is_string($dir) && is_array($details) && is_string($details['vendor-dir'] ?? null)) {
                $lastInstall = $details['last-install'] ?? null;
                $method = $details['method'] ?? null;
                $records[$dir] = [
                    'vendor-dir' => $details['vendor-dir'],
                    'last-install' => is_string($lastInstall) ? $lastInstall : '',
                    'method' => is_string($method) ? $method : '',
                ];
            }
        }

        return $records;
    }

    /**
     * @param callable(array<string, Record>): array<string, mixed> $change
     */
    private function update(callable $change): void
    {
        $lock = new StoreLock($this->file . '.lock');
        if (!$lock->acquireExclusive(self::LOCK_TIMEOUT)) {
            throw new StoreException(sprintf('Cannot lock %s: %s', $this->file, $lock->failure()));
        }
        try {
            $projects = $change($this->records());
            ksort($projects);
            $json = json_encode(['projects' => $projects], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            $temp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($temp, $json) === false || !@rename($temp, $this->file)) {
                @unlink($temp);
                throw new StoreException('Cannot write ' . $this->file);
            }
        } finally {
            $lock->release();
        }
    }
}

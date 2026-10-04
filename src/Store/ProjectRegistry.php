<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * `projects.json`: the projects that have linked from the store, with their vendor directory. Pruning
 * reads their `vendor/composer/installed.json` to know which entries are still in use.
 *
 * Every change is a read-modify-write under its own short lock (not the store lock, which installs
 * hold shared), and the file is replaced with an atomic rename.
 */
final class ProjectRegistry
{
    private const LOCK_TIMEOUT = 10.0;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * @return array<string, array{vendor-dir: string, last-install: string}> project directory => details
     */
    public function projects(): array
    {
        $data = json_decode((string) @file_get_contents($this->file), true);
        $entries = is_array($data) && is_array($data['projects'] ?? null) ? $data['projects'] : [];
        $projects = [];
        foreach ($entries as $dir => $details) {
            if (is_string($dir) && is_array($details) && is_string($details['vendor-dir'] ?? null)) {
                $lastInstall = is_string($details['last-install'] ?? null) ? $details['last-install'] : '';
                $projects[$dir] = ['vendor-dir' => $details['vendor-dir'], 'last-install' => $lastInstall];
            }
        }

        return $projects;
    }

    public function register(string $projectDir, string $vendorDir): void
    {
        $this->update(static function (array $projects) use ($projectDir, $vendorDir): array {
            $projects[$projectDir] = ['vendor-dir' => $vendorDir, 'last-install' => gmdate(DATE_ATOM)];

            return $projects;
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
     * @param callable(array<string, array{vendor-dir: string, last-install: string}>): array<string, mixed> $change
     */
    private function update(callable $change): void
    {
        $lock = new StoreLock($this->file . '.lock');
        if (!$lock->acquireExclusive(self::LOCK_TIMEOUT)) {
            throw new StoreException(sprintf('Cannot lock %s: %s', $this->file, $lock->failure()));
        }
        try {
            $projects = $change($this->projects());
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

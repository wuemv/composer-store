<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * Finds and deletes what no project needs any more.
 *
 * An entry is in use while any of its files is hard-linked from a vendor/ directory (link count above
 * 1), or while a registered project's vendor/composer/installed.json lists that package version: the
 * second rule covers projects that copied rather than linked. Deleting a used entry would not break a
 * project (its links keep the data), but it would stop the sharing, so used entries are kept.
 *
 * Only call prune() while holding the store lock exclusively: installs must not be running.
 */
final class Pruner
{
    public function __construct(private readonly Store $store)
    {
    }

    public function plan(): PrunePlan
    {
        [$used, $missingProjects] = $this->entriesUsedByProjects();
        $unused = [];
        foreach ($this->store->entries() as $entry) {
            $stats = EntryStats::of($entry->filesDir());
            if ($stats->linkedFiles === 0 && !isset($used[$entry->path])) {
                $unused[] = ['entry' => $entry, 'stats' => $stats];
            }
        }

        return new PrunePlan($unused, $this->store->tempDirs(), $missingProjects);
    }

    /**
     * Deletes everything in the plan that it can.
     *
     * @return list<string> what could not be deleted, and why
     */
    public function prune(PrunePlan $plan): array
    {
        $failures = [];
        foreach ($plan->entries as $unused) {
            try {
                $this->store->removeEntry($unused['entry']);
            } catch (StoreException $e) {
                $failures[] = $e->getMessage();
            }
        }
        foreach ($plan->tempDirs as $dir) {
            Store::removeTree($dir);
            if (file_exists($dir)) {
                $failures[] = 'Cannot delete all of ' . $dir;
            }
        }
        if ($plan->missingProjects !== []) {
            try {
                $this->store->projects()->forget($plan->missingProjects);
            } catch (StoreException $e) {
                $failures[] = $e->getMessage();
            }
        }

        return $failures;
    }

    /**
     * @return array{array<string, true>, list<string>} entry paths in use, and projects that are gone
     */
    private function entriesUsedByProjects(): array
    {
        $used = [];
        $missing = [];
        foreach ($this->store->projects()->projects() as $projectDir => $project) {
            if (!is_dir($projectDir)) {
                $missing[] = $projectDir;
                continue;
            }
            foreach (self::installedVersions($project['vendor-dir']) as [$name, $version, $reference]) {
                $used[$this->store->entryPath($name, $version, $reference)] = true;
            }
        }

        return [$used, $missing];
    }

    /**
     * The dist-installed package versions in a vendor/ directory.
     *
     * @return list<array{string, string, string}> name, pretty version, dist reference
     */
    private static function installedVersions(string $vendorDir): array
    {
        $data = json_decode((string) @file_get_contents($vendorDir . '/composer/installed.json'), true);
        if (!is_array($data)) {
            return [];
        }
        // Composer 2 wraps the list in {"packages": [...]}; Composer 1 wrote the list itself.
        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : $data;

        $versions = [];
        foreach ($packages as $package) {
            $name = is_array($package) ? $package['name'] ?? null : null;
            $version = is_array($package) ? $package['version'] ?? null : null;
            $dist = is_array($package) && is_array($package['dist'] ?? null) ? $package['dist'] : [];
            $reference = $dist['reference'] ?? null;
            if (is_string($name) && is_string($version) && is_string($reference) && $reference !== '') {
                $versions[] = [$name, $version, $reference];
            }
        }

        return $versions;
    }
}

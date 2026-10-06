<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

use ComposerStore\Link\CloneInfo;
use ComposerStore\Link\Device;
use ComposerStore\Link\Method;
use ComposerStore\Store\EntryStats;
use ComposerStore\Store\InstalledPackages;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;

/**
 * Measures what the projects in a directory take on disk, from their files rather than from what the
 * store remembers of their installs: a project installed without the plugin holds copies, whatever
 * projects.json says.
 *
 * A package counts as coming from the store when one of its files is a hard link to the store's file
 * (same inode) or a clone of it (same clone ID). Clones only show through getattrlist(2), so on macOS
 * without FFI, and for projects that cloned with reflinks on Linux, clones and copies look alike, and
 * the figures that depend on them are null.
 */
final class UsageScanner
{
    /** @var array<string, int> what each store entry takes, by entry path, kept between snapshots */
    private array $entrySizes = [];

    /** @var array<string, string> a file of each entry, relative to its files/ dir, by entry path */
    private array $samples = [];

    /** @var array<string, array{fingerprint: string, usage: ProjectUsage, at: float}> by project dir */
    private array $projects = [];

    /**
     * @param string $os           the PHP_OS_FAMILY to behave as: on Darwin, files may be clones that
     *                             only getattrlist(2) tells from copies
     * @param float  $refreshAfter seconds after which an unchanged project is measured again, one per
     *                             snapshot, for what its fingerprint cannot show (an edited file)
     */
    public function __construct(
        private readonly Store $store,
        private readonly ?CloneInfo $clones,
        private readonly string $os = PHP_OS_FAMILY,
        private readonly float $refreshAfter = 60.0,
    ) {
    }

    /**
     * Measures the projects in $dir. The first snapshot reads every file; later ones measure again only
     * the projects whose vendor/ changed or that lost a store entry they linked, plus the one due for
     * a refresh, and reuse the figures of the others.
     *
     * @param ?callable(int, int): void $progress told the projects done so far and how many there are
     */
    public function snapshot(string $dir, ?callable $progress = null): Snapshot
    {
        [$storeBytes, $gone] = $this->measureStore();
        $registered = $this->store->projects()->projects();
        $dirs = ProjectFinder::find($dir);
        $this->projects = array_intersect_key($this->projects, array_flip($dirs));
        $due = $this->dueForRefresh();
        $projects = [];
        $used = [];
        foreach ($dirs as $i => $projectDir) {
            $method = $registered[realpath($projectDir) ?: $projectDir]['method'] ?? null;
            $project = $this->measured($projectDir, $this->mayHideClones($method), $gone, $projectDir === $due);
            $projects[] = $project;
            foreach ($project->entries as $entry) {
                $used[$entry] = $this->entrySizes[$entry] ?? 0;
            }
            if ($progress !== null) {
                $progress($i + 1, count($dirs));
            }
        }
        $hidden = array_filter($projects, static fn (ProjectUsage $project): bool => $project->fromStore === null);
        $free = @disk_free_space($dir);

        return new Snapshot(
            $dir,
            $free === false ? null : (int) $free,
            count($this->entrySizes),
            $storeBytes,
            $hidden === [] ? array_sum($used) : null,
            $projects,
            $this->clones !== null,
            Device::same($this->store->root(), $dir),
        );
    }

    /**
     * A project's figures: measured again when its vendor/ changed, when a store entry it linked is
     * gone, or when it is due for a refresh; otherwise the ones it had.
     *
     * @param list<string> $gone store entries removed since the last snapshot
     */
    private function measured(string $dir, bool $mayHideClones, array $gone, bool $due): ProjectUsage
    {
        $vendorDir = self::vendorDir($dir);
        $fingerprint = self::fingerprint($vendorDir) . ($mayHideClones ? ':hidden' : '');
        $known = $this->projects[$dir] ?? null;
        if (
            $known !== null && !$due && $known['fingerprint'] === $fingerprint
            && array_intersect($known['usage']->entries, $gone) === []
        ) {
            return $known['usage'];
        }
        $usage = $this->project($dir, $vendorDir, $mayHideClones);
        $this->projects[$dir] = ['fingerprint' => $fingerprint, 'usage' => $usage, 'at' => microtime(true)];

        return $usage;
    }

    /**
     * The project measured longest ago, when that is over refreshAfter seconds ago.
     */
    private function dueForRefresh(): ?string
    {
        $due = null;
        $before = microtime(true) - $this->refreshAfter;
        foreach ($this->projects as $dir => $known) {
            if ($known['at'] <= $before) {
                $due = $dir;
                $before = $known['at'];
            }
        }

        return $due;
    }

    /**
     * What changes when packages are installed, updated or removed, read with a few dozen stat()
     * calls: vendor/ and its entries, whose directories gain or lose a package directory, and
     * installed.json, which Composer writes last.
     */
    private static function fingerprint(string $vendorDir): string
    {
        $paths = [$vendorDir, $vendorDir . '/composer/installed.json'];
        foreach (@scandir($vendorDir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $paths[] = $vendorDir . '/' . $name;
            }
        }
        $parts = [];
        foreach ($paths as $path) {
            $stat = @stat($path);
            $parts[] = $stat === false ? '-' : $stat['ino'] . '.' . $stat['mtime'] . '.' . $stat['size'];
        }

        return md5(implode('|', $parts));
    }

    private function project(string $dir, string $vendorDir, bool $mayHideClones): ProjectUsage
    {
        $fromStore = 0;
        $methods = [];
        $entries = [];
        $hidden = false;
        foreach (InstalledPackages::read($vendorDir) as $package) {
            if ($package['source'] !== 'dist') {
                continue; // installed from source: never from the store
            }
            $entry = $this->store->entryPath($package['name'], $package['version'], $package['reference']);
            $sample = $this->sample($entry);
            if ($sample === null) {
                continue; // not in the store
            }
            $link = $this->linkOf(
                $entry . '/' . StoreEntry::FILES_DIR . '/' . $sample,
                $vendorDir . '/' . $package['install-path'] . '/' . $sample,
                $mayHideClones
            );
            if ($link === false) {
                $hidden = true;
            } elseif ($link !== null) {
                $fromStore++;
                $methods[$link->value] = $link;
                $entries[] = $entry;
            }
        }
        [$files, $bytes, $own] = $this->measureVendor($vendorDir);

        return new ProjectUsage(
            $dir,
            $vendorDir,
            InstalledPackages::count($vendorDir),
            $hidden ? null : $fromStore,
            array_values($methods),
            $entries,
            $files,
            $bytes,
            $mayHideClones ? null : $own,
        );
    }

    /**
     * How a project's file relates to the store file it would be linked from: a hard link or a clone of
     * it, null for a copy (or no file), false where a clone cannot be told from a copy.
     */
    private function linkOf(string $storeFile, string $vendorFile, bool $mayHideClones): Method|false|null
    {
        $store = @stat($storeFile);
        $vendor = @lstat($vendorFile);
        if ($store === false || $vendor === false) {
            return null;
        }
        // The link count guards against a platform that reports no inode numbers.
        if ($store['dev'] === $vendor['dev'] && $store['ino'] === $vendor['ino'] && $vendor['nlink'] > 1) {
            return Method::Hardlink;
        }
        if ($this->clones === null) {
            return $mayHideClones ? false : null;
        }
        $storeClone = $this->clones->of($storeFile);
        $vendorClone = $this->clones->of($vendorFile);

        return $storeClone !== null && $vendorClone !== null && $storeClone['clone-id'] === $vendorClone['clone-id']
            ? Method::Reflink
            : null;
    }

    /**
     * @return array{int, int, int} files, what they take as copies, and what no other file shares:
     *         hard-linked files share all their data, a clone only the blocks it has not rewritten
     */
    private function measureVendor(string $vendorDir): array
    {
        $files = $bytes = $own = 0;
        if (!is_dir($vendorDir)) {
            return [0, 0, 0];
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($vendorDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $info) {
            if (!$info instanceof \SplFileInfo || $info->isLink() || !$info->isFile()) {
                continue;
            }
            $stat = @lstat($info->getPathname());
            if ($stat === false) {
                continue; // removed while counting, as during an install
            }
            $size = $stat['blocks'] >= 0 ? $stat['blocks'] * 512 : $stat['size'];
            $files++;
            $bytes += $size;
            if ($stat['nlink'] === 1) {
                $own += min($size, $this->clones?->of($info->getPathname())['private-bytes'] ?? $size);
            }
        }

        return [$files, $bytes, $own];
    }

    /**
     * What all store entries take. Entries do not change once published, so each is measured once;
     * entries that are gone are forgotten.
     *
     * @return array{int, list<string>} the bytes, and the entries gone since the last snapshot
     */
    private function measureStore(): array
    {
        $sizes = [];
        foreach ($this->store->entries() as $entry) {
            $sizes[$entry->path] = $this->entrySizes[$entry->path] ?? EntryStats::of($entry->filesDir())->bytes;
        }
        $gone = array_keys(array_diff_key($this->entrySizes, $sizes));
        $this->entrySizes = $sizes;

        return [array_sum($sizes), $gone];
    }

    /**
     * A file of the entry to compare with the project's: composer.json, which nearly every package
     * has, or else the first file found. Null while the entry does not exist.
     */
    private function sample(string $entry): ?string
    {
        if (isset($this->samples[$entry])) {
            return $this->samples[$entry];
        }
        $files = $entry . '/' . StoreEntry::FILES_DIR;
        $sample = null;
        if (is_file($files . '/composer.json') && !is_link($files . '/composer.json')) {
            $sample = 'composer.json';
        } elseif (is_dir($files)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($files, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $info) {
                if ($info instanceof \SplFileInfo && $info->isFile() && !$info->isLink()) {
                    $sample = substr($info->getPathname(), strlen($files) + 1);
                    break;
                }
            }
        }
        if ($sample !== null) {
            $this->samples[$entry] = $sample;
        }

        return $sample;
    }

    /**
     * Whether a project may hold clones this machine cannot tell from copies: on macOS, any project
     * without FFI; on Linux, one whose last install with the plugin cloned with reflinks.
     */
    private function mayHideClones(?Method $registered): bool
    {
        return $this->clones === null && ($this->os === 'Darwin' || $registered === Method::Reflink);
    }

    private static function vendorDir(string $projectDir): string
    {
        $manifest = json_decode((string) @file_get_contents($projectDir . '/composer.json'), true);
        $config = is_array($manifest) && is_array($manifest['config'] ?? null) ? $manifest['config'] : [];
        $dir = $config['vendor-dir'] ?? null;
        if (!is_string($dir) || $dir === '') {
            return $projectDir . '/vendor';
        }

        return preg_match('{^(/|\\\\|[A-Za-z]:[\\\\/])}', $dir) === 1 ? $dir : $projectDir . '/' . $dir;
    }
}

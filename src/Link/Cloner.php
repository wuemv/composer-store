<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * Copies trees as copy-on-write clones (reflinks). A clone shares the original's data blocks until one
 * side changes, so it takes almost no space, yet it is an independent file: editing or chmod-ing a clone
 * in vendor/ never changes the store.
 *
 * - Linux: GNU `cp --reflink=always`, on filesystems that have reflinks (Btrfs, XFS, bcachefs, ZFS 2.2+).
 * - macOS: clonefile(2) through PHP's FFI extension, which clones a whole package in one call, on APFS.
 *   Without FFI, `cp -c`, which quietly copies on other filesystems, so the volume type is checked
 *   rather than trusting cp.
 * - Anything else, Windows included: no clones.
 */
final class Cloner
{
    private ?CloneFile $cloneFile = null;

    private bool $cloneFileLoaded = false;

    /**
     * @param string $os a PHP_OS_FAMILY value
     * @param bool $inProcess whether to clone with clonefile(2) on macOS where FFI allows it, rather than cp
     */
    public function __construct(
        private readonly string $os = PHP_OS_FAMILY,
        private readonly bool $inProcess = true,
    ) {
    }

    /**
     * Whether this system can clone files at all, on a filesystem that supports it.
     */
    public function isAvailable(): bool
    {
        return $this->os === 'Linux' || $this->os === 'Darwin';
    }

    /**
     * Whether cloneTree() clones inside this process, with one clonefile(2) call, rather than running cp.
     * A package then takes about a millisecond, so there is nothing to gain from cloning in parallel.
     */
    public function clonesInProcess(): bool
    {
        return $this->cloneFile() !== null;
    }

    /**
     * Whether files in $dir can be cloned within its filesystem. Clones a small file in $dir to find out.
     */
    public function isSupported(string $dir): bool
    {
        $cloneFile = $this->cloneFile();
        if ($cloneFile !== null) {
            // Unlike cp -c, clonefile(2) fails where the volume cannot clone.
            $clone = static function (string $from, string $to) use ($cloneFile): bool {
                try {
                    $cloneFile->clone($from, $to);
                } catch (LinkException) {
                    return false;
                }

                return true;
            };
        } elseif ($this->os === 'Darwin') {
            if ($this->macVolumeType($dir) !== 'apfs') {
                return false;
            }
            $clone = static fn (string $from, string $to): bool
                => Command::run(['cp', '-c', '--', $from, $to])[0] === 0;
        } elseif ($this->os === 'Linux') {
            $clone = static fn (string $from, string $to): bool
                => Command::run(['cp', '--reflink=always', '--', $from, $to])[0] === 0;
        } else {
            return false;
        }

        $probe = sprintf('%s/.reflink-probe-%s', $dir, bin2hex(random_bytes(4)));
        if (@file_put_contents($probe, 'composer-store reflink probe') === false) {
            return false;
        }
        try {
            return $clone($probe, $probe . '.clone');
        } finally {
            @unlink($probe);
            @unlink($probe . '.clone');
        }
    }

    /**
     * Clones the tree at $source to $target, which must not exist. Files keep their mode and times.
     *
     * @throws LinkException when cloning fails, possibly leaving part of $target behind
     */
    public function cloneTree(string $source, string $target): void
    {
        $cloneFile = $this->cloneFile();
        if ($cloneFile !== null) {
            $cloneFile->clone($source, $target);

            return;
        }
        [$status, , $error] = Command::run($this->treeCommand($source, $target));
        if ($status !== 0) {
            throw new LinkException(sprintf('cannot clone %s to %s: %s', $source, $target, $error));
        }
    }

    /**
     * The cp command cloneTree() runs without clonefile(2), for callers that run it themselves, several
     * at once.
     *
     * @return list<string>
     *
     * @throws LinkException on a system without clones
     */
    public function treeCommand(string $source, string $target): array
    {
        return match ($this->os) {
            'Linux' => ['cp', '-R', '-T', '--reflink=always', '--preserve=mode,timestamps', '--', $source, $target],
            'Darwin' => ['cp', '-c', '-R', '-p', '--', $source, $target],
            default => throw new LinkException('reflinks are not supported on ' . $this->os),
        };
    }

    private function cloneFile(): ?CloneFile
    {
        if (!$this->cloneFileLoaded) {
            $this->cloneFileLoaded = true;
            $this->cloneFile = $this->os === 'Darwin' && $this->inProcess ? CloneFile::load() : null;
        }

        return $this->cloneFile;
    }

    /**
     * The filesystem type of the volume holding $dir, from `mount`: the longest mount point that
     * contains it. macOS firmlinks such as /Users lead to the data volume, which is APFS like `/`.
     */
    private function macVolumeType(string $dir): ?string
    {
        $path = realpath($dir);
        [$status, $mounts] = Command::run(['mount'], captureOutput: true);
        if ($path === false || $status !== 0) {
            return null;
        }

        $type = null;
        $longest = -1;
        foreach (explode("\n", $mounts) as $line) {
            // "/dev/disk3s5 on /System/Volumes/Data (apfs, local, journaled, nobrowse)"
            if (preg_match('{^.+? on (/.*?) \(([^,)]+)}', $line, $match) !== 1) {
                continue;
            }
            $point = rtrim($match[1], '/');
            $contains = $path === $match[1] || str_starts_with($path, $point . '/');
            if ($contains && strlen($point) > $longest) {
                $longest = strlen($point);
                $type = trim($match[2]);
            }
        }

        return $type;
    }
}

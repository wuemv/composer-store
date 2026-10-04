<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * Copies trees as copy-on-write clones (reflinks) with the system `cp`. A clone shares the original's
 * data blocks until one side changes, so it takes almost no space, yet it is an independent file:
 * editing or chmod-ing a clone in vendor/ never changes the store.
 *
 * - Linux: GNU `cp --reflink=always`, on filesystems that have reflinks (Btrfs, XFS, bcachefs, ZFS 2.2+).
 * - macOS: `cp -c` (clonefile), on APFS. It quietly copies on other filesystems, so the volume type is
 *   checked rather than trusting cp.
 * - Anything else, Windows included: no clones.
 */
final class Cloner
{
    /**
     * @param string $os a PHP_OS_FAMILY value
     */
    public function __construct(private readonly string $os = PHP_OS_FAMILY)
    {
    }

    /**
     * Whether this system can clone files at all, on a filesystem that supports it.
     */
    public function isAvailable(): bool
    {
        return $this->os === 'Linux' || $this->os === 'Darwin';
    }

    /**
     * Whether files in $dir can be cloned within its filesystem. Clones a small file in $dir to find out.
     */
    public function isSupported(string $dir): bool
    {
        if ($this->os === 'Darwin') {
            if ($this->macVolumeType($dir) !== 'apfs') {
                return false;
            }
            $command = ['cp', '-c'];
        } elseif ($this->os === 'Linux') {
            $command = ['cp', '--reflink=always'];
        } else {
            return false;
        }

        $probe = sprintf('%s/.reflink-probe-%s', $dir, bin2hex(random_bytes(4)));
        if (@file_put_contents($probe, 'composer-store reflink probe') === false) {
            return false;
        }
        try {
            return $this->run([...$command, '--', $probe, $probe . '.clone'])[0] === 0;
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
        [$status, , $error] = $this->run($this->treeCommand($source, $target));
        if ($status !== 0) {
            throw new LinkException(sprintf('cannot clone %s to %s: %s', $source, $target, $error));
        }
    }

    /**
     * The cp command cloneTree() runs, for callers that run it themselves, several at once.
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

    /**
     * The filesystem type of the volume holding $dir, from `mount`: the longest mount point that
     * contains it. macOS firmlinks such as /Users lead to the data volume, which is APFS like `/`.
     */
    private function macVolumeType(string $dir): ?string
    {
        $path = realpath($dir);
        [$status, $mounts] = $this->run(['mount'], captureOutput: true);
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

    /**
     * Runs a command without a shell.
     *
     * @param list<string> $command
     *
     * @return array{int, string, string} exit status, output when captured, first line of the errors
     */
    private function run(array $command, bool $captureOutput = false): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $captureOutput ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
            // Errors only when output is not captured: reading two pipes one after the other could block.
            2 => $captureOutput ? ['file', '/dev/null', 'w'] : ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [-1, '', 'cannot run ' . $command[0]];
        }
        $stream = $pipes[$captureOutput ? 1 : 2];
        $text = (string) stream_get_contents($stream);
        fclose($stream);
        $status = proc_close($process);

        if ($captureOutput) {
            return [$status, $text, ''];
        }
        $error = trim(strtok($text, "\n") ?: '');
        if ($error === '') {
            $error = $status === 127 ? $command[0] . ' not found' : 'exit status ' . $status;
        }

        return [$status, '', $error];
    }
}

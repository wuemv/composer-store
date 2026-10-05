<?php

declare(strict_types=1);

namespace ComposerStore\Link;

use ComposerStore\Store\Store;

/**
 * Hard-links whole trees with the system `cp`, one process per package, which Composer can run several
 * at a time as it runs unzip. On ext4 that put the files of 148 packages in place in 0.19 s, against
 * 0.48 s for a link() per file in PHP (benchmarks/run-placement.php).
 *
 * Linux only, where cp is GNU's, BusyBox's or uutils', and only once a probe has shown that this cp
 * hard-links files and keeps symlinks as they are. Elsewhere the Linker links file by file.
 */
final class HardLinker
{
    /**
     * @param string $os a PHP_OS_FAMILY value
     */
    public function __construct(private readonly string $os = PHP_OS_FAMILY)
    {
    }

    /**
     * Whether cp hard-links trees within $dir as treeCommand() means it to. Links a small tree to find out.
     */
    public function isSupported(string $dir): bool
    {
        if ($this->os !== 'Linux') {
            return false;
        }

        $probe = sprintf('%s/.hardlink-probe-%s', $dir, bin2hex(random_bytes(4)));
        $linked = $probe . '.linked';
        try {
            if (
                !@mkdir($probe)
                || @file_put_contents($probe . '/file', 'composer-store hard link probe') === false
                || !@symlink('file', $probe . '/link')
                || Command::run($this->treeCommand($probe, $linked))[0] !== 0
            ) {
                return false;
            }
            $inode = @fileinode($probe . '/file');

            // A cp that follows symlinks would leave a second hard link to the file instead.
            return $inode !== false
                && @fileinode($linked . '/file') === $inode
                && is_link($linked . '/link')
                && @readlink($linked . '/link') === 'file';
        } finally {
            Store::removeTree($linked);
            Store::removeTree($probe);
        }
    }

    /**
     * The command that hard-links the tree at $source to $target, which must not exist: real directories,
     * a hard link for every file, and symlinks kept as symlinks (-P), as hard links to themselves.
     *
     * @return list<string>
     */
    public function treeCommand(string $source, string $target): array
    {
        return ['cp', '-R', '-l', '-P', '--', $source, $target];
    }
}

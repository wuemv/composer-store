<?php

declare(strict_types=1);

namespace ComposerStore\Store;

/**
 * A hash of a directory tree: every entry's relative path and type, each file's content and exec
 * bit, each symlink's target. Timestamps, owners and write bits are left out, so the hash survives
 * read-only mode and is the same for every extraction of the same package version.
 */
final class TreeHasher
{
    public const PREFIX = 'sha256:';

    public static function hash(string $dir): string
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $info) {
            if ($info instanceof \SplFileInfo) {
                $relative = str_replace('\\', '/', substr($info->getPathname(), strlen($dir) + 1));
                $entries[$relative] = $info;
            }
        }
        ksort($entries, SORT_STRING);

        $context = hash_init('sha256');
        foreach ($entries as $relative => $info) {
            if ($info->isLink()) {
                $record = ['link', $relative, (string) readlink($info->getPathname())];
            } elseif ($info->isDir()) {
                $record = ['dir', $relative];
            } else {
                $exec = ($info->getPerms() & 0111) !== 0 ? 'x' : '-';
                $record = ['file', $relative, $exec, (string) hash_file('sha256', $info->getPathname())];
            }
            // NUL cannot appear in paths, so it separates fields and records unambiguously.
            hash_update($context, implode("\0", $record) . "\0\0");
        }

        return self::PREFIX . hash_final($context);
    }
}

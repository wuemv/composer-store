<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Support;

use ComposerStore\Store\Store;

final class Files
{
    public static function tempDir(string $prefix): string
    {
        $dir = sprintf('%s/%s-%s', rtrim(sys_get_temp_dir(), '/\\'), $prefix, bin2hex(random_bytes(4)));
        self::makeDir($dir);

        return (string) realpath($dir);
    }

    public static function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);
        if ($file === false) {
            throw new \RuntimeException('Cannot create a temp file');
        }

        return $file;
    }

    public static function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
    }

    public static function remove(string $path): void
    {
        Store::removeTree($path);
    }

    /**
     * Copies a tree, keeping file permissions.
     */
    public static function copyTree(string $from, string $to): void
    {
        self::makeDir($to);
        foreach (self::entries($from) as $relative => $info) {
            $target = $to . '/' . $relative;
            if ($info->isDir()) {
                self::makeDir($target);
            } elseif (!copy($info->getPathname(), $target) || !chmod($target, $info->getPerms() & 0777)) {
                throw new \RuntimeException('Cannot copy ' . $info->getPathname());
            }
        }
    }

    /**
     * Content hash and permissions of every file in a tree.
     *
     * @return array<string, string> relative path => "<sha256> <octal permissions>"
     */
    public static function snapshot(string $dir): array
    {
        $snapshot = [];
        foreach (self::entries($dir) as $relative => $info) {
            if ($info->isFile()) {
                $hash = hash_file('sha256', $info->getPathname());
                $snapshot[$relative] = $hash . ' ' . decoct($info->getPerms() & 0777);
            }
        }

        return $snapshot;
    }

    /**
     * @return array<string, \SplFileInfo> relative path => entry, parents before children
     */
    public static function entries(string $dir): array
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

        return $entries;
    }

    /**
     * @return array<mixed>
     */
    public static function readJson(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException($file . ' does not hold a JSON object');
        }

        return $data;
    }

    /**
     * @param array<mixed> $data
     */
    public static function writeJson(string $file, array $data): void
    {
        self::makeDir(dirname($file));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($file, $json . "\n");
    }
}

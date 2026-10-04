<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * Which filesystem a path is on. Links and clones only work within one.
 */
final class Device
{
    /**
     * The device ID of a path, or of its closest existing parent: where it would be created.
     */
    public static function of(string $path): ?int
    {
        while (!file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }
            $path = $parent;
        }
        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'];
    }

    public static function same(string $path, string $other): bool
    {
        $device = self::of($path);

        return $device !== null && $device === self::of($other);
    }
}

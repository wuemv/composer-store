<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Support;

/**
 * Stand-ins for `cp` and `mount`, to put first on the PATH. The cp drops the clone options (`-c`,
 * `--reflink=always`) and copies with the real cp, so the code that clones runs on filesystems without
 * reflinks; it can log its arguments, or fail like a cp on such a filesystem. The mount prints a given
 * mount table, for the macOS volume check.
 */
final class FakeCp
{
    /**
     * @param string|null $log  file that gets one line of arguments per call
     * @param bool        $fail whether to fail like `cp --reflink=always` on ext4
     */
    public static function write(string $dir, ?string $log = null, bool $fail = false): void
    {
        $record = $log === null ? '' : sprintf('echo "$*" >> %s', escapeshellarg($log));
        if ($fail) {
            $body = <<<'SH'
                echo "cp: failed to clone: Operation not supported" >&2
                exit 1
                SH;
        } else {
            $body = sprintf(<<<'SH'
                for arg do
                    shift
                    case "$arg" in
                        -c|--reflink=always) ;;
                        *) set -- "$@" "$arg" ;;
                    esac
                done
                exec %s "$@"
                SH, escapeshellarg(self::realCp()));
        }
        self::script($dir . '/cp', $record . "\n" . $body);
    }

    /**
     * A mount that prints $table, lines such as "/dev/disk3s5 on / (apfs, local, journaled)".
     */
    public static function writeMount(string $dir, string $table): void
    {
        self::script($dir . '/mount', sprintf('printf %%s %s', escapeshellarg($table)));
    }

    private static function realCp(): string
    {
        foreach (['/bin/cp', '/usr/bin/cp'] as $cp) {
            if (is_executable($cp)) {
                return $cp;
            }
        }
        throw new \RuntimeException('No cp in /bin or /usr/bin');
    }

    private static function script(string $file, string $body): void
    {
        Files::makeDir(dirname($file));
        file_put_contents($file, "#!/bin/sh\n" . $body . "\n");
        chmod($file, 0755);
    }
}

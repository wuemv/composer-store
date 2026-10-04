<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * macOS's clonefile(2), called through PHP's FFI extension. One call clones a file or a whole directory
 * tree inside the kernel: about 1.6 ms per package, where running `cp -c` takes about 15 ms per package
 * even ten at a time (benchmarks/run-placement.php).
 */
final class CloneFile
{
    private function __construct(private readonly \FFI $ffi)
    {
    }

    /**
     * The binding, or null where clonefile(2) cannot be called: not macOS, no FFI extension, or FFI not
     * allowed (ffi.enable allows it on the command line by default).
     */
    public static function load(): ?self
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !extension_loaded('ffi')) {
            return null;
        }
        try {
            return new self(\FFI::cdef(
                'int clonefile(const char *src, const char *dst, uint32_t flags);'
                . ' int *__error(void);'
                . ' char *strerror(int errnum);'
            ));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Clones $source, a file or a directory tree, to $target, which must not exist. The clones keep the
     * modes, times and extended attributes of the originals.
     *
     * @throws LinkException with the system's error, possibly leaving part of $target behind
     */
    public function clone(string $source, string $target): void
    {
        if ($this->ffi->clonefile($source, $target, 0) === 0) {
            return;
        }
        $error = \FFI::string($this->ffi->strerror($this->ffi->__error()[0]));

        throw new LinkException(sprintf('cannot clone %s to %s: %s', $source, $target, $error));
    }
}

<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * What an APFS file shares with other files, from macOS's getattrlist(2) called through PHP's FFI
 * extension: its clone ID, which pure clones of one file have in common, and the bytes of its data that
 * no other file shares. Nothing else shows clones: du and Finder count each one at its full size.
 */
final class CloneInfo
{
    private const ATTR_BIT_MAP_COUNT = 5;
    private const ATTR_CMN_RETURNED_ATTRS = 0x80000000;
    private const ATTR_CMNEXT_PRIVATESIZE = 0x00000008;
    private const ATTR_CMNEXT_CLONEID = 0x00000100;
    private const FSOPT_NOFOLLOW = 0x00000001;
    private const FSOPT_ATTR_CMN_EXTENDED = 0x00000020;

    /** struct attrlist: bitmapcount, reserved, then the common, volume, dir, file and fork groups */
    private const LIST_FORMAT = 'vvVVVVV';
    private const LIST_SIZE = 24;

    /**
     * What getattrlist(2) writes back: its length, the attribute groups it returned (the extended
     * common attributes count as the fork group), then the private size and the clone ID, in the
     * order of their bits. macOS runs little-endian on Intel and Apple silicon alike.
     */
    private const ATTRS_FORMAT = 'Vlength/V4groups/Vfork/qprivate/Qclone';
    private const ATTRS_SIZE = 40;

    /**
     * @param \FFI\CData $list  the request, the same for every call
     * @param \FFI\CData $attrs the buffer getattrlist(2) answers in
     */
    private function __construct(
        private readonly \FFI $ffi,
        private readonly \FFI\CData $list,
        private readonly \FFI\CData $attrs,
    ) {
    }

    /**
     * The binding, or null where getattrlist(2) cannot be called: not macOS, no FFI extension, or FFI
     * not allowed (ffi.enable allows it on the command line by default).
     */
    public static function load(): ?self
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !extension_loaded('ffi')) {
            return null;
        }
        try {
            $ffi = \FFI::cdef(
                'int getattrlist(const char *path, void *list, void *buf, size_t size, unsigned int options);'
            );
            $list = $ffi->new('uint8_t[' . self::LIST_SIZE . ']');
            $attrs = $ffi->new('uint8_t[' . self::ATTRS_SIZE . ']');
            $request = pack(
                self::LIST_FORMAT,
                self::ATTR_BIT_MAP_COUNT,
                0,
                self::ATTR_CMN_RETURNED_ATTRS,
                0,
                0,
                0,
                self::ATTR_CMNEXT_PRIVATESIZE | self::ATTR_CMNEXT_CLONEID
            );
            \FFI::memcpy($list, $request, self::LIST_SIZE);

            return new self($ffi, $list, $attrs);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The file's clone ID and the bytes of its data that no other file shares, or null when the file
     * system does not report them (not APFS) or the file is gone. A hard link is one file: its private
     * bytes leave out what other names of the same file share, which link counts show instead.
     *
     * @return array{clone-id: int, private-bytes: int}|null
     */
    public function of(string $path): ?array
    {
        $options = self::FSOPT_NOFOLLOW | self::FSOPT_ATTR_CMN_EXTENDED;
        $list = \FFI::addr($this->list);
        if ($this->ffi->getattrlist($path, $list, \FFI::addr($this->attrs), self::ATTRS_SIZE, $options) !== 0) {
            return null;
        }
        $attrs = unpack(self::ATTRS_FORMAT, \FFI::string($this->attrs, self::ATTRS_SIZE));
        $wanted = self::ATTR_CMNEXT_PRIVATESIZE | self::ATTR_CMNEXT_CLONEID;
        if (
            $attrs === false || !is_int($attrs['fork'] ?? null) || ($attrs['fork'] & $wanted) !== $wanted
            || !is_int($attrs['private'] ?? null) || !is_int($attrs['clone'] ?? null)
        ) {
            return null;
        }

        return ['clone-id' => $attrs['clone'], 'private-bytes' => $attrs['private']];
    }
}

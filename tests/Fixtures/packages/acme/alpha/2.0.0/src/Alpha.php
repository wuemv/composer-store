<?php

namespace Acme\Alpha;

final class Alpha
{
    public const VERSION = '2.0.0';

    /**
     * Where PHP thinks this file lives: inside the project's vendor/ when linked correctly.
     */
    public static function dir(): string
    {
        return __DIR__;
    }
}

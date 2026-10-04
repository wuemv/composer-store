<?php

namespace Acme\Beta;

use Acme\Alpha\Alpha;

final class Beta
{
    public static function describe(): string
    {
        return 'beta runs with alpha ' . Alpha::VERSION;
    }
}

<?php

namespace Acme\Alpha\Support;

final class Farewell
{
    public static function bye(string $name): string
    {
        return 'Bye, ' . $name;
    }
}

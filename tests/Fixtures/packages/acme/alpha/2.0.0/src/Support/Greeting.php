<?php

namespace Acme\Alpha\Support;

final class Greeting
{
    public static function hello(string $name): string
    {
        return 'Hello, ' . $name;
    }
}

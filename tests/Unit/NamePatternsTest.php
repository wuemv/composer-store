<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit;

use ComposerStore\NamePatterns;
use PHPUnit\Framework\TestCase;

final class NamePatternsTest extends TestCase
{
    public function testNamesMatchWholeAndCaseInsensitively(): void
    {
        $patterns = new NamePatterns(['Acme/Foo']);

        $this->assertTrue($patterns->matches('acme/foo'));
        $this->assertTrue($patterns->matches('ACME/FOO'));
        $this->assertFalse($patterns->matches('acme/foobar'));
        $this->assertFalse($patterns->matches('xacme/foo'));
    }

    public function testAStarMatchesAnyCharacters(): void
    {
        $patterns = new NamePatterns(['acme/*', '*/logger']);

        $this->assertTrue($patterns->matches('acme/foo'));
        $this->assertTrue($patterns->matches('monolog/logger'));
        $this->assertFalse($patterns->matches('acmex/foo'));
        $this->assertFalse($patterns->matches('monolog/loggers'));
    }

    public function testOtherCharactersAreLiteral(): void
    {
        $patterns = new NamePatterns(['acme/foo.bar']);

        $this->assertTrue($patterns->matches('acme/foo.bar'));
        $this->assertFalse($patterns->matches('acme/fooxbar'));
    }

    public function testNoPatternsMatchNothing(): void
    {
        $patterns = new NamePatterns([]);

        $this->assertTrue($patterns->isEmpty());
        $this->assertFalse($patterns->matches('acme/foo'));
        $this->assertFalse((new NamePatterns(['acme/foo']))->isEmpty());
    }
}

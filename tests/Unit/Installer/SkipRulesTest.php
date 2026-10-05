<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Installer;

use Composer\Package\Package;
use ComposerStore\Installer\SkipRules;
use PHPUnit\Framework\TestCase;

final class SkipRulesTest extends TestCase
{
    public function testExcludedPackagesAreSkipped(): void
    {
        $rules = new SkipRules(['acme/foo'], []);

        $this->assertSame('excluded in extra.composer-store.exclude', $rules->reason(self::package('acme/foo')));
        $this->assertNull($rules->reason(self::package('acme/foobar')));
        $this->assertNull($rules->reason(self::package('other/foo')));
    }

    public function testExcludePatternsMatchWholeNamesWithWildcards(): void
    {
        $rules = new SkipRules(['acme/*', '*/sdk-*', 'Vendor/Name'], []);

        $this->assertNotNull($rules->reason(self::package('acme/anything')));
        $this->assertNotNull($rules->reason(self::package('aws/sdk-php')));
        $this->assertNotNull($rules->reason(self::package('vendor/name')), 'matching is case-insensitive');
        $this->assertNull($rules->reason(self::package('not-acme/x')));
        $this->assertNull($rules->reason(self::package('aws/aws-sdk-php')));
    }

    public function testPatchedPackagesAreSkipped(): void
    {
        $rules = new SkipRules([], ['acme/patched' => true]);

        $this->assertSame('patched by a patches plugin', $rules->reason(self::package('acme/patched')));
        $this->assertNull($rules->reason(self::package('acme/other')));
    }

    public function testPatternCharactersOtherThanTheWildcardAreLiteral(): void
    {
        $rules = new SkipRules(['acme/a.b'], []);

        $this->assertNotNull($rules->reason(self::package('acme/a.b')));
        $this->assertNull($rules->reason(self::package('acme/axb')));
    }

    private static function package(string $name): Package
    {
        return new Package($name, '1.0.0.0', '1.0.0');
    }
}

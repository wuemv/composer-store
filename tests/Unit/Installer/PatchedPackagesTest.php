<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Installer;

use Composer\Package\Package;
use Composer\Package\RootPackage;
use ComposerStore\Installer\PatchedPackages;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

final class PatchedPackagesTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = Files::tempDir('patches-test');
    }

    protected function tearDown(): void
    {
        Files::remove($this->project);
    }

    public function testNothingIsPatchedWithoutPatches(): void
    {
        $this->assertSame([], PatchedPackages::find(self::root([]), $this->project, []));
    }

    public function testPatchesInTheRootComposerJson(): void
    {
        $root = self::root(['patches' => [
            'Acme/Foo' => ['Fix it' => 'patches/foo.patch'],
            'acme/bar' => [['description' => 'Fix it', 'url' => 'patches/bar.patch']],
        ]]);

        $this->assertSame(['acme/foo' => true, 'acme/bar' => true], PatchedPackages::find($root, $this->project, []));
    }

    public function testAPatchesFileFromComposerPatches1(): void
    {
        $patches = ['patches' => ['acme/foo' => ['Fix' => 'x.patch']]];
        Files::writeJson($this->project . '/composer.patches.json', $patches);
        $root = self::root(['patches-file' => 'composer.patches.json']);

        $this->assertSame(['acme/foo' => true], PatchedPackages::find($root, $this->project, []));
    }

    public function testPatchesFilesFromComposerPatches2(): void
    {
        Files::writeJson($this->project . '/patches.json', ['patches' => ['acme/default' => []]]);
        Files::writeJson($this->project . '/custom.json', ['patches' => ['acme/custom' => []]]);
        Files::writeJson($this->project . '/patches.lock.json', ['patches' => ['acme/locked' => []]]);

        $customRoot = self::root(['composer-patches' => ['patches-file' => 'custom.json']]);
        $default = PatchedPackages::find(self::root([]), $this->project, []);
        $custom = PatchedPackages::find($customRoot, $this->project, []);

        $this->assertSame(['acme/default' => true, 'acme/locked' => true], $default);
        $this->assertSame(['acme/custom' => true, 'acme/locked' => true], $custom);
    }

    public function testPatchesDeclaredByDependencies(): void
    {
        $dependency = new Package('acme/patcher', '1.0.0.0', '1.0.0');
        $dependency->setExtra(['patches' => ['acme/target' => ['Fix' => 'patches/target.patch']]]);

        $patched = PatchedPackages::find(self::root([]), $this->project, [$dependency]);

        $this->assertSame(['acme/target' => true], $patched);
    }

    public function testUnreadablePatchFilesAreIgnored(): void
    {
        file_put_contents($this->project . '/patches.json', '{not json');
        $root = self::root(['patches-file' => 'missing.json', 'patches' => 'not an object']);

        $this->assertSame([], PatchedPackages::find($root, $this->project, []));
    }

    /**
     * @param array<mixed> $extra
     */
    private static function root(array $extra): RootPackage
    {
        $root = new RootPackage('test/app', '1.0.0.0', '1.0.0');
        $root->setExtra($extra);

        return $root;
    }
}

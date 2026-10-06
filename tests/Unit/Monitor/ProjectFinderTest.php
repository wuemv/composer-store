<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Monitor;

use ComposerStore\Monitor\ProjectFinder;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

final class ProjectFinderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('project-finder-test');
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testFindsProjectsUpToThreeLevelsDown(): void
    {
        foreach (['shop', 'clients/acme/portal', 'clients/acme/api', 'a/b/c/too-deep'] as $project) {
            Files::writeJson($this->dir . '/' . $project . '/composer.json', []);
        }

        $this->assertSame(
            [$this->dir . '/clients/acme/api', $this->dir . '/clients/acme/portal', $this->dir . '/shop'],
            ProjectFinder::find($this->dir)
        );
    }

    public function testADirectoryWithAComposerJsonIsTheOnlyProject(): void
    {
        Files::writeJson($this->dir . '/composer.json', []);
        Files::writeJson($this->dir . '/packages/local/composer.json', []);

        $this->assertSame([$this->dir], ProjectFinder::find($this->dir . '/'));
    }

    public function testSkipsVendorNodeModulesAndHiddenDirectories(): void
    {
        foreach (['vendor/acme/lib', 'node_modules/pkg', '.cache/app', 'app'] as $dir) {
            Files::writeJson($this->dir . '/' . $dir . '/composer.json', []);
        }

        $this->assertSame([$this->dir . '/app'], ProjectFinder::find($this->dir));
    }

    public function testFindsNothingInAMissingDirectory(): void
    {
        $this->assertSame([], ProjectFinder::find($this->dir . '/missing'));
    }
}

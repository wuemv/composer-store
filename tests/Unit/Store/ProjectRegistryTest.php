<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Store;

use ComposerStore\Store\ProjectRegistry;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

final class ProjectRegistryTest extends TestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('registry-test');
        $this->file = $this->dir . '/projects.json';
    }

    protected function tearDown(): void
    {
        Files::remove($this->dir);
    }

    public function testThereAreNoProjectsBeforeTheFirstInstall(): void
    {
        $this->assertSame([], (new ProjectRegistry($this->file))->projects());
    }

    public function testRegisterAddsOrUpdatesAProject(): void
    {
        $registry = new ProjectRegistry($this->file);

        $registry->register('/work/b', '/work/b/vendor');
        $registry->register('/work/a', '/work/a/vendor');
        $registry->register('/work/b', '/work/b/lib');

        $projects = $registry->projects();
        $this->assertSame(['/work/a', '/work/b'], array_keys($projects));
        $this->assertSame('/work/b/lib', $projects['/work/b']['vendor-dir']);
        $this->assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $projects['/work/a']['last-install']));
        $this->assertSame([], glob($this->dir . '/*.tmp'), 'a temp file was left behind');
    }

    public function testForgetRemovesProjects(): void
    {
        $registry = new ProjectRegistry($this->file);
        foreach (['/work/a', '/work/b', '/work/c'] as $project) {
            $registry->register($project, $project . '/vendor');
        }

        $registry->forget(['/work/a', '/work/c', '/work/never-registered']);

        $this->assertSame(['/work/b'], array_keys($registry->projects()));
    }

    public function testAnUnreadableFileHoldsNoProjectsAndIsReplacedOnTheNextChange(): void
    {
        file_put_contents($this->file, '{"projects": ');
        $registry = new ProjectRegistry($this->file);

        $this->assertSame([], $registry->projects());

        $registry->register('/work/a', '/work/a/vendor');
        $this->assertSame(['/work/a'], array_keys($registry->projects()));
    }

    public function testRecordsWithoutAVendorDirAreSkipped(): void
    {
        Files::writeJson($this->file, ['projects' => [
            '/work/a' => ['vendor-dir' => '/work/a/vendor'],
            '/work/b' => 'not a record',
            '/work/c' => ['vendor-dir' => 3],
        ]]);

        $this->assertSame(
            ['/work/a' => ['vendor-dir' => '/work/a/vendor', 'last-install' => '']],
            (new ProjectRegistry($this->file))->projects()
        );
    }
}

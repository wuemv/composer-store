<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;

/**
 * `read-only: true` removes the write bits of store files, so an accidental edit of a linked file in
 * vendor/ fails instead of changing the package for every project.
 */
final class ReadOnlyTest extends IntegrationTestCase
{
    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testStoreFilesAndTheirLinksAreReadOnly(): void
    {
        $project = $this->createProject('app', ['acme/beta' => '1.0.0'], ['read-only' => true]);

        $this->composer($project, 'install');

        $this->assertLinkedFromStore($project, 'acme/alpha', '2.0.0');
        $this->assertReadOnly($this->storeEntry('acme/alpha', '2.0.0') . '/files');
        $this->assertReadOnly($this->storeEntry('acme/beta', '1.0.0') . '/files');
        // Binaries are copies, which Composer makes executable as usual.
        $this->assertTrue(is_executable($project . '/vendor/acme/beta/bin/beta'));

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return; // root ignores file permissions, so the write below would succeed
        }
        $this->assertFalse(
            @file_put_contents($project . '/vendor/acme/alpha/src/Alpha.php', '// edited', FILE_APPEND),
            'editing a linked vendor file should fail'
        );
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testComposerCanStillUpdateAndRemoveReadOnlyPackages(): void
    {
        $require = ['acme/alpha' => '2.0.0', 'acme/gamma' => '1.0.0'];
        $project = $this->createProject('app', $require, ['read-only' => true]);
        $this->composer($project, 'install');
        $entry = $this->storeEntry('acme/alpha', '2.0.0');
        $before = Files::snapshot($entry);

        $this->composer($project, 'require', 'acme/alpha:1.0.0');
        $this->composer($project, 'remove', 'acme/gamma');

        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
        $this->assertDirectoryDoesNotExist($project . '/vendor/acme/gamma');
        $this->assertSame($before, Files::snapshot($entry), 'the old entry changed');
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testEntriesCreatedBeforeReadOnlyWasEnabledBecomeReadOnlyWhenLinked(): void
    {
        $first = $this->createProject('first', ['acme/gamma' => '1.0.0']);
        $second = $this->createProject('second', ['acme/gamma' => '1.0.0'], ['read-only' => true]);
        $this->composer($first, 'install');
        $files = $this->storeEntry('acme/gamma', '1.0.0') . '/files';
        $this->assertNotSame(0, fileperms($files . '/src/Gamma.php') & 0200);

        $this->composer($second, 'install');

        $this->assertReadOnly($files);
    }

    #[RequiresOperatingSystemFamily('Windows')]
    public function testReadOnlyIsIgnoredOnWindows(): void
    {
        $project = $this->createProject('app', ['acme/gamma' => '1.0.0'], ['read-only' => true]);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('read-only mode is not supported on Windows yet, ignoring it', $output);
        $this->assertLinkedFromStore($project, 'acme/gamma', '1.0.0');
    }

    private function assertReadOnly(string $dir): void
    {
        clearstatcache();
        foreach (Files::entries($dir) as $relative => $info) {
            if ($info->isFile()) {
                $this->assertSame(0, $info->getPerms() & 0222, $relative . ' is writable');
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;

final class InstallTest extends IntegrationTestCase
{
    public function testInstallExtractsIntoTheStoreAndLinksIntoVendor(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString(
            'Installing acme/alpha (1.0.0): Extracting archive into the store',
            $output
        );
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');

        $meta = Files::readJson($this->storeEntry('acme/alpha', '1.0.0') . '/.store-meta.json');
        $this->assertSame('acme/alpha', $meta['name']);
        $this->assertSame('1.0.0', $meta['version']);
        $this->assertSame($this->env->reference('acme/alpha', '1.0.0'), $meta['reference']);
        $this->assertSame(['type' => 'zip'], array_intersect_key((array) $meta['dist'], ['type' => true]));

        // __DIR__ in a linked file is the project's vendor/, not the store.
        $dir = $this->php($project, 'echo Acme\Alpha\Alpha::dir();');
        $expected = implode(DIRECTORY_SEPARATOR, [realpath($project), 'vendor', 'acme', 'alpha', 'src']);
        $this->assertSame($expected, $dir);
    }

    public function testASecondProjectLinksTheSameFilesFromTheStore(): void
    {
        $first = $this->createProject('first', ['acme/alpha' => '1.0.0']);
        $second = $this->createProject('second', ['acme/alpha' => '1.0.0']);
        $this->composer($first, 'install');

        $output = $this->composer($second, 'install');

        $this->assertStringContainsString('Installing acme/alpha (1.0.0): Linking from store', $output);
        $this->assertLinkedFromStore($second, 'acme/alpha', '1.0.0');
        $file = '/vendor/acme/alpha/src/Alpha.php';
        $this->assertSame(fileinode($first . $file), fileinode($second . $file));
        $this->assertSame(3, $this->linkCount($second . $file), 'expected the store and two projects');
    }

    public function testInstallFromALockFileIntoAnEmptyStore(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0']);
        $this->composer($project, 'install');
        Files::remove($project . '/vendor');
        $this->store = $this->work . '/empty-store';

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('Installing dependencies from lock file', $output);
        $this->assertStringContainsString('Installing acme/alpha (1.0.0): Extracting archive into the store', $output);
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
        $this->assertLinkedFromStore($project, 'acme/gamma', '1.0.0');
    }

    public function testTheLockFileAndInstalledMetadataMatchAPlainComposerInstall(): void
    {
        $withPlugin = $this->createProject('with-plugin', ['acme/beta' => '1.0.0', 'acme/gamma' => '1.0.0']);
        $withoutPlugin = $this->work . '/without-plugin';
        Files::copyTree($withPlugin, $withoutPlugin);

        $this->composer($withPlugin, 'install');
        $this->composerWithoutPlugin($withoutPlugin, 'install');

        $this->assertLinkedFromStore($withPlugin, 'acme/gamma', '1.0.0');
        foreach (['composer.lock', 'vendor/composer/installed.json', 'vendor/composer/installed.php'] as $file) {
            $this->assertFileEquals($withoutPlugin . '/' . $file, $withPlugin . '/' . $file, $file . ' differs');
        }
    }

    public function testBinariesAreCopiedSoComposersChmodDoesNotReachTheStore(): void
    {
        $project = $this->createProject('app', ['acme/beta' => '1.0.0']);

        $this->composer($project, 'install');

        $this->assertLinkedFromStore($project, 'acme/beta', '1.0.0', copied: ['bin/beta']);
        $storeCopy = $this->storeEntry('acme/beta', '1.0.0') . '/files/bin/beta';
        $this->assertSame(0, fileperms($storeCopy) & 0111, 'Composer made the store copy executable');
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->assertTrue(is_executable($project . '/vendor/acme/beta/bin/beta'));
        }

        $result = Process::run([PHP_BINARY, 'vendor/bin/beta'], $project, Process::environmentWithoutComposer());
        $this->assertSame('beta runs with alpha 2.0.0' . PHP_EOL, $result->stdout, $result->describe());
    }

    public function testPackagesThatAreNotLibrariesAreLeftToComposer(): void
    {
        $project = $this->createProject('app', ['acme/delta' => '1.0.0']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('Installing acme/delta (1.0.0): Extracting archive', $output);
        $this->assertStringNotContainsString('into the store', $output);
        $this->assertNotLinked($project, 'acme/delta');
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/delta');
    }
}

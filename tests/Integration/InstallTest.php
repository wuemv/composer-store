<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Store\TreeHasher;
use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;

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

        $entry = $this->storeEntry('acme/alpha', '1.0.0');
        $meta = Files::readJson($entry . '/.store-meta.json');
        $this->assertSame('acme/alpha', $meta['name']);
        $this->assertSame('1.0.0', $meta['version']);
        $this->assertSame($this->env->reference('acme/alpha', '1.0.0'), $meta['reference']);
        $this->assertSame(['type' => 'zip'], array_intersect_key((array) $meta['dist'], ['type' => true]));
        $this->assertSame(TreeHasher::hash($entry . '/files'), $meta['tree_hash']);

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

    public function testPackagesInTheStoreAreNotFetchedFromComposersCache(): void
    {
        $first = $this->createProject('first', ['acme/alpha' => '1.0.0']);
        $second = $this->createProject('second', ['acme/alpha' => '1.0.0']);
        $fetched = '{(Loading|Downloading) acme/alpha \(1\.0\.0\)}';

        // The first install extracts the archive into the store, so Composer fetches it as usual.
        $this->assertMatchesRegularExpression($fetched, $this->composer($first, 'install', '-vvv'));

        // The second links the store's copy, and would leave the archive unused.
        $output = $this->composer($second, 'install', '-vvv');
        $this->assertStringContainsString('acme/alpha (1.0.0): Linking from store', $output);
        $this->assertDoesNotMatchRegularExpression($fetched, $output);
        $this->assertLinkedFromStore($second, 'acme/alpha', '1.0.0');
        $installed = Files::readJson($second . '/vendor/composer/installed.json');
        $packages = is_array($installed['packages'] ?? null) ? $installed['packages'] : [];
        $alpha = is_array($packages[0] ?? null) ? $packages[0] : [];
        $this->assertSame('acme/alpha', $alpha['name'] ?? null);
        $this->assertSame('dist', $alpha['installation-source'] ?? null, 'as if Composer had fetched the archive');
        $this->assertSame([], glob($second . '/vendor/composer/tmp-*') ?: []);
    }

    #[RequiresOperatingSystem('Linux|Darwin')]
    public function testAStoreEntryDeletedAfterTheDownloadStepIsFetchedAfterAll(): void
    {
        $first = $this->createProject('first', ['acme/alpha' => '1.0.0']);
        $this->composer($first, 'install');
        // Deleted by hand between Composer's download step, which the store let it skip, and the install.
        $second = $this->createProject('second', ['acme/alpha' => '1.0.0']);
        $manifest = Files::readJson($second . '/composer.json');
        $entry = $this->storeEntry('acme/alpha', '1.0.0');
        $manifest['scripts'] = ['pre-package-install' => 'rm -rf ' . escapeshellarg($entry)];
        Files::writeJson($second . '/composer.json', $manifest);

        $output = $this->composer($second, 'install', '-vvv');

        $this->assertMatchesRegularExpression('{(Loading|Downloading) acme/alpha \(1\.0\.0\)}', $output);
        $this->assertStringContainsString('acme/alpha (1.0.0): Extracting archive into the store', $output);
        $this->assertLinkedFromStore($second, 'acme/alpha', '1.0.0');
    }

    public function testOnLinuxEachPackageIsHardLinkedByOneCp(): void
    {
        $project = $this->createProject('app', ['acme/beta' => '1.0.0']);

        $output = $this->composer($project, 'install', '-vvv');

        if (PHP_OS_FAMILY === 'Linux') {
            // Composer runs the cps alongside each other, as it runs unzip.
            $this->assertStringContainsString('with hard links, through cp', $output);
            $command = "'cp' '-R' '-l' '-P' '--' .*/files' ";
            $this->assertMatchesRegularExpression('{Executing async command \(.*\): ' . $command . '}', $output);
        } else {
            $this->assertStringContainsString('with hard links, file by file', $output);
        }
        $this->assertLinkedFromStore($project, 'acme/alpha', '2.0.0');
        $this->assertLinkedFromStore($project, 'acme/beta', '1.0.0', copied: ['bin/beta']);
    }

    public function testProjectTypeDependenciesAreLinkedLikeLibraries(): void
    {
        // Tools such as laravel/pint are of type project: Composer installs them like libraries.
        $project = $this->createProject('app', ['acme/delta' => '1.0.0']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('Installing acme/delta (1.0.0): Extracting archive into the store', $output);
        $this->assertLinkedFromStore($project, 'acme/delta', '1.0.0');
    }

    public function testOtherPackageTypesAreLeftToComposer(): void
    {
        $project = $this->createProject('app', ['acme/epsilon' => '1.0.0']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('Installing acme/epsilon (1.0.0): Extracting archive', $output);
        $this->assertStringNotContainsString('into the store', $output);
        $this->assertNotLinked($project, 'acme/epsilon');
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/epsilon');
    }
}

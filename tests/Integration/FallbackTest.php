<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Support\Files;

/**
 * Cases where the plugin must step aside and let Composer install as usual.
 */
final class FallbackTest extends IntegrationTestCase
{
    public function testCopyModeLeavesInstallsToComposer(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0'], ['mode' => 'copy']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('Installing acme/alpha (1.0.0): Extracting archive', $output);
        $this->assertStringNotContainsString('into the store', $output);
        $this->assertNotLinked($project, 'acme/alpha');
        $this->assertDirectoryDoesNotExist($this->store);
    }

    public function testAStoreOnAnotherFilesystemFallsBackToCopyingWithAWarning(): void
    {
        $this->store = $this->otherFilesystem() . '/store';
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('is not on the same filesystem as', $output);
        $this->assertStringNotContainsString('into the store', $output);
        $this->assertNotLinked($project, 'acme/alpha');
    }

    public function testAnUnknownModeWarnsAndUsesAuto(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0'], ['mode' => 'fast']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('unknown mode "fast"', $output);
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
    }

    public function testSourceInstallsAreNotLinked(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);

        $output = $this->composer($project, 'install', '--prefer-source');

        $this->assertStringNotContainsString('into the store', $output);
        $this->assertDirectoryExists($project . '/vendor/acme/alpha/.git');
        // Only the work tree: git itself hard-links .git/objects when cloning from Composer's local cache.
        $this->assertSame(1, $this->linkCount($project . '/vendor/acme/alpha/src/Alpha.php'));
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/alpha');
    }

    public function testPathRepositoryPackagesAreNotLinked(): void
    {
        $project = $this->work . '/app';
        $package = $project . '/packages/alpha';
        Files::copyTree(dirname(__DIR__) . '/Fixtures/packages/acme/alpha/1.0.0', $package);
        $manifest = Files::readJson($package . '/composer.json') + ['version' => '1.0.0'];
        Files::writeJson($package . '/composer.json', $manifest);
        Files::writeJson($project . '/composer.json', [
            'name' => 'test/app',
            'type' => 'project',
            'repositories' => [['type' => 'path', 'url' => 'packages/alpha'], ['packagist.org' => false]],
            'require' => ['acme/alpha' => '1.0.0'],
        ]);

        $output = $this->composer($project, 'install');

        $this->assertStringNotContainsString('into the store', $output);
        $this->assertSame('1.0.0', $this->php($project, 'echo Acme\Alpha\Alpha::VERSION;'));
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/alpha');
    }

    public function testAnEntryWithTheSameKeyButOtherContentIsLeftAlone(): void
    {
        $reference = $this->env->reference('acme/alpha', '1.0.0');
        $entry = sprintf('%s/packages/acme/alpha/1.0.0-%s', $this->store, substr($reference, 0, 12));
        Files::makeDir($entry . '/files');
        file_put_contents($entry . '/files/marker', 'not alpha');
        Files::writeJson($entry . '/.store-meta.json', ['name' => 'acme/alpha', 'reference' => 'another-reference']);
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);

        $output = $this->composer($project, 'install');

        $this->assertStringContainsString('does not hold acme/alpha, installing it without the store', $output);
        $this->assertNotLinked($project, 'acme/alpha');
        $this->assertFileDoesNotExist($project . '/vendor/acme/alpha/marker');
        $this->assertSame('not alpha', file_get_contents($entry . '/files/marker'));
    }

    public function testWithoutThePluginALinkedVendorStillWorksAndAReinstallMakesItNormal(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');
        $entry = $this->storeEntry('acme/alpha', '1.0.0');
        $before = Files::snapshot($entry);

        // Once the plugin is gone, Composer sees an ordinary, complete vendor/.
        $output = $this->composerWithoutPlugin($project, 'install');
        $this->assertStringContainsString('Nothing to install, update or remove', $output);
        $this->assertSame('1.0.0', $this->php($project, 'echo Acme\Alpha\Alpha::VERSION;'));

        // Reinstalling vendor/ gives independent files and leaves the store as it was.
        Files::remove($project . '/vendor');
        $this->composerWithoutPlugin($project, 'install');
        $this->assertNotLinked($project, 'acme/alpha');
        $this->assertSame($before, Files::snapshot($entry));
        $this->assertSame(1, $this->linkCount($entry . '/files/src/Alpha.php'));
    }
}

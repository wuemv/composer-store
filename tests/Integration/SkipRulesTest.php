<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Support\Files;

/**
 * Excluded and patched packages are installed by Composer as usual; everything else is still linked.
 */
final class SkipRulesTest extends IntegrationTestCase
{
    private const REQUIRE = ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0'];

    public function testExcludedPackagesAreInstalledWithoutTheStore(): void
    {
        $project = $this->createProject('app', self::REQUIRE, ['exclude' => ['acme/gam*']]);

        $output = $this->composer($project, 'install', '-v');

        // Once, although both Composer's download step and its install step ask.
        $this->assertSame(1, substr_count(
            $output,
            'composer-store: acme/gamma is excluded in extra.composer-store.exclude, installing it without the store'
        ));
        $this->assertGammaWasNotLinked($project);
    }

    public function testPackagesPatchedInTheRootComposerJsonAreInstalledWithoutTheStore(): void
    {
        $patches = ['acme/gamma' => ['Rename gamma' => 'patches/gamma.patch']];
        $project = $this->createProject('app', self::REQUIRE, extra: ['patches' => $patches]);

        $output = $this->composer($project, 'install', '-v');

        $this->assertStringContainsString('composer-store: acme/gamma is patched by a patches plugin', $output);
        $this->assertGammaWasNotLinked($project);
    }

    public function testPackagesPatchedFromAPatchesFileAreInstalledWithoutTheStore(): void
    {
        $project = $this->createProject('app', self::REQUIRE, extra: ['patches-file' => 'composer.patches.json']);
        Files::writeJson($project . '/composer.patches.json', [
            'patches' => ['acme/gamma' => ['Rename gamma' => 'patches/gamma.patch']],
        ]);

        $this->composer($project, 'install');

        $this->assertGammaWasNotLinked($project);
    }

    public function testPackagesPatchedByADependencyAreInstalledWithoutTheStore(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0', 'acme/patcher' => '1.0.0']);

        $this->composer($project, 'install');

        $this->assertGammaWasNotLinked($project);
        $this->assertLinkedFromStore($project, 'acme/patcher', '1.0.0');
    }

    private function assertGammaWasNotLinked(string $project): void
    {
        $this->assertNotLinked($project, 'acme/gamma');
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/gamma');
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
    }
}

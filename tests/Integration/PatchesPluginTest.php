<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Integration\Support\FixtureRepository;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\Group;

/**
 * cweagans/composer-patches really patching a package: the patch lands in the project's own copy and
 * the store is untouched. Needs Packagist and GitHub, so it only runs with `composer test:network`.
 */
#[Group('network')]
final class PatchesPluginTest extends IntegrationTestCase
{
    public function testComposerPatchesPatchesACopyAndLeavesTheStoreAlone(): void
    {
        $project = $this->createProject(
            'app',
            ['cweagans/composer-patches' => '^1.7', 'acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0'],
            extra: ['patches' => ['acme/gamma' => ['Rename gamma' => 'patches/gamma.patch']]],
        );
        $manifest = Files::readJson($project . '/composer.json');
        // Packagist for the patches plugin, installed from git so no GitHub archive download is needed.
        $fixtures = ['type' => 'composer', 'url' => FixtureRepository::fileUrl($this->env->repository)];
        $manifest['repositories'] = [$fixtures];
        $manifest['config'] = [
            'allow-plugins' => ['cweagans/composer-patches' => true],
            'preferred-install' => ['cweagans/*' => 'source', '*' => 'dist'],
        ];
        Files::writeJson($project . '/composer.json', $manifest);
        Files::makeDir($project . '/patches');
        $patch = dirname(__DIR__) . '/Fixtures/packages/acme/patcher/1.0.0/patches/gamma.patch';
        copy($patch, $project . '/patches/gamma.patch');

        $result = $this->runComposer($project, ['install'], network: true);

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertSame('patched gamma', $this->php($project, 'echo Acme\Gamma\Gamma::NAME;'));
        $this->assertNotLinked($project, 'acme/gamma');
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/gamma');
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
    }
}

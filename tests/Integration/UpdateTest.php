<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Tests\Support\Files;

/**
 * `require`, `update` and `remove` on a project whose vendor/ is linked from the store.
 */
final class UpdateTest extends IntegrationTestCase
{
    public function testRequireLinksTheNewPackage(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');

        $output = $this->composer($project, 'require', 'acme/gamma:1.0.0');

        $this->assertStringContainsString('Installing acme/gamma (1.0.0): Extracting archive into the store', $output);
        $this->assertLinkedFromStore($project, 'acme/gamma', '1.0.0');
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
    }

    public function testUpdateLinksTheNewVersionAndKeepsTheOldEntry(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');
        $oldEntry = $this->storeEntry('acme/alpha', '1.0.0');
        $before = Files::snapshot($oldEntry);
        $this->setRequirement($project, 'acme/alpha', '^1.0');

        $output = $this->composer($project, 'update');

        $this->assertStringContainsString(
            'Upgrading acme/alpha (1.0.0 => 1.1.0): Extracting archive into the store',
            $output
        );
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.1.0');
        $this->assertSame($before, Files::snapshot($oldEntry), 'the store entry of the old version changed');
        $this->assertSame('1.1.0', $this->php($project, 'echo Acme\Alpha\Alpha::VERSION;'));
    }

    public function testDowngradeLinksTheOlderVersionFromTheStore(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);
        $this->composer($project, 'install');
        $this->composer($project, 'require', 'acme/alpha:2.0.0');
        $this->assertFileExists($project . '/vendor/acme/alpha/src/Support/Farewell.php');

        $output = $this->composer($project, 'require', 'acme/alpha:1.0.0');

        $this->assertStringContainsString('Downgrading acme/alpha (2.0.0 => 1.0.0): Linking from store', $output);
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
        $this->assertFileDoesNotExist($project . '/vendor/acme/alpha/src/Support/Farewell.php');
    }

    public function testRemoveDeletesTheProjectsLinksButNotTheStoreEntry(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0']);
        $this->composer($project, 'install');
        $entry = $this->storeEntry('acme/gamma', '1.0.0');
        $before = Files::snapshot($entry);

        $output = $this->composer($project, 'remove', 'acme/gamma');

        $this->assertStringContainsString('Removing acme/gamma (1.0.0)', $output);
        $this->assertDirectoryDoesNotExist($project . '/vendor/acme/gamma');
        $this->assertSame($before, Files::snapshot($entry), 'removing a package changed its store entry');
        $this->assertSame(1, $this->linkCount($entry . '/files/src/Gamma.php'), 'only the store should hold it now');
        $this->assertLinkedFromStore($project, 'acme/alpha', '1.0.0');
    }

    public function testTwoProjectsOnDifferentVersionsOfTheSamePackage(): void
    {
        $old = $this->createProject('old', ['acme/alpha' => '1.0.0']);
        $new = $this->createProject('new', ['acme/alpha' => '2.0.0']);

        $this->composer($old, 'install');
        $this->composer($new, 'install');

        $this->assertLinkedFromStore($old, 'acme/alpha', '1.0.0');
        $this->assertLinkedFromStore($new, 'acme/alpha', '2.0.0');
        $this->assertSame('1.0.0', $this->php($old, 'echo Acme\Alpha\Alpha::VERSION;'));
        $this->assertSame('2.0.0', $this->php($new, 'echo Acme\Alpha\Alpha::VERSION;'));
    }
}

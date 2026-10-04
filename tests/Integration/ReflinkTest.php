<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Link\Cloner;
use ComposerStore\Link\Method;
use ComposerStore\Tests\Support\FakeCp;
use ComposerStore\Tests\Support\Files;

/**
 * Installs with reflinks. Real clones where the test directory supports them: APFS on macOS, or the
 * directory in COMPOSER_STORE_TEST_REFLINK_DIR, such as a Btrfs or XFS mount. Elsewhere on Linux, a
 * stand-in cp that copies runs the same code. Windows has no reflinks, so these tests are skipped there.
 */
final class ReflinkTest extends IntegrationTestCase
{
    private bool $realClones;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = getenv('COMPOSER_STORE_TEST_REFLINK_DIR');
        if (is_string($dir) && $dir !== '') {
            $this->work = $dir . '/' . basename($this->work);
            Files::makeDir($this->work);
            $this->store = $this->work . '/store';
        }

        $this->realClones = (new Cloner())->isSupported($this->work);
        if (!$this->realClones) {
            if (PHP_OS_FAMILY !== 'Linux') {
                $this->markTestSkipped('No reflinks here, and the stand-in cp needs Linux');
            }
            FakeCp::write($this->work . '/fake-bin');
            $this->composerEnv['PATH'] = $this->work . '/fake-bin' . PATH_SEPARATOR . getenv('PATH');
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (getenv('COMPOSER_STORE_TEST_REFLINK_DIR') && getenv('COMPOSER_STORE_TEST_KEEP') === false) {
            Files::remove($this->work);
        }
    }

    public function testAutoModeClonesPackagesIntoVendor(): void
    {
        $project = $this->createProject('app', ['acme/beta' => '1.0.0'], ['mode' => 'auto']);

        $output = $this->composer($project, 'install', '-v');

        $this->assertStringContainsString('with reflinks', $output);
        $this->assertSame(Method::Reflink, $this->linkMethod($project));
        $this->assertLinkedFromStore($project, 'acme/alpha', '2.0.0');
        $this->assertLinkedFromStore($project, 'acme/beta', '1.0.0');
        $alphaDir = $this->php($project, 'echo Acme\Alpha\Alpha::dir();');
        $this->assertSame(realpath($project . '/vendor/acme/alpha/src'), $alphaDir);

        // Composer chmods binaries in place: the clone changes, the store does not.
        $storeBin = $this->storeEntry('acme/beta', '1.0.0') . '/files/bin/beta';
        $this->assertSame(0, fileperms($storeBin) & 0111);
        $this->assertTrue(is_executable($project . '/vendor/acme/beta/bin/beta'));
    }

    public function testEditsInVendorDoNotReachTheStore(): void
    {
        $first = $this->createProject('first', ['acme/alpha' => '1.0.0'], ['mode' => 'reflink']);
        $second = $this->createProject('second', ['acme/alpha' => '1.0.0'], ['mode' => 'reflink']);
        $this->composer($first, 'install');
        $this->composer($second, 'install');

        file_put_contents($first . '/vendor/acme/alpha/src/Alpha.php', "\n// local debugging\n", FILE_APPEND);

        $this->assertStringNotContainsString('debugging', (string) file_get_contents(
            $this->storeEntry('acme/alpha', '1.0.0') . '/files/src/Alpha.php'
        ));
        $this->assertLinkedFromStore($second, 'acme/alpha', '1.0.0');
        $result = $this->runComposer($first, ['store:verify']);
        $this->assertSame(0, $result->exitCode, $result->describe());
    }

    public function testHardlinkModeHardLinksEvenWhereReflinksWork(): void
    {
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0'], ['mode' => 'hardlink']);

        $this->composer($project, 'install');

        $this->assertSame(Method::Hardlink, $this->linkMethod($project));
        $this->assertSame(2, $this->linkCount($project . '/vendor/acme/alpha/src/Alpha.php'));
    }

    public function testStatusAndPruneSeeClonedPackages(): void
    {
        $first = $this->createProject('first', ['acme/alpha' => '1.0.0', 'acme/gamma' => '1.0.0'], ['mode' => 'auto']);
        $second = $this->createProject('second', ['acme/alpha' => '1.0.0'], ['mode' => 'auto']);
        $this->composer($first, 'install');
        $this->composer($second, 'install');

        // Clones leave every link count at 1: only the registry shows which entries are in use.
        $this->assertSame(1, $this->linkCount($this->storeEntry('acme/alpha', '1.0.0') . '/files/src/Alpha.php'));
        $status = $this->json($this->runComposer($first, ['store:status', '--format=json']));
        $this->assertSame(0, $status['links']);
        $this->assertSame(3, $status['clones'], 'alpha in both projects, gamma in one');
        $this->assertGreaterThan(0, $status['cloned-bytes']);
        $this->assertSame($status['cloned-bytes'], $status['saved-bytes']);
        $this->assertIsArray($status['project']);
        $this->assertSame('reflink', $status['project']['method']);
        $this->assertStringContainsString('Nothing to prune', $this->composer($first, 'store:prune', '--force'));

        $this->composer($first, 'remove', 'acme/gamma');
        $this->setRequirement($second, 'acme/alpha', '1.1.0');
        $this->composer($second, 'update');

        $output = $this->composer($first, 'store:prune', '--force');
        $this->assertStringContainsString('Deleted 1 unused entry', $output);
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/gamma');
        $this->assertDirectoryExists($this->storeEntry('acme/alpha', '1.0.0'), 'first still uses alpha 1.0.0');
        $this->assertLinkedFromStore($second, 'acme/alpha', '1.1.0');
    }

    public function testTheReflinkDirectoryHasReflinks(): void
    {
        // Catches a CI filesystem that silently lost its reflinks, which would leave the stand-in cp.
        $dir = getenv('COMPOSER_STORE_TEST_REFLINK_DIR');
        if (!is_string($dir) || $dir === '') {
            $this->markTestSkipped('COMPOSER_STORE_TEST_REFLINK_DIR is not set');
        }

        $this->assertTrue($this->realClones, $dir . ' does not support reflinks');
    }
}

<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration;

use ComposerStore\Store\StoreEntry;
use ComposerStore\Store\StoreLock;
use ComposerStore\Store\TreeHasher;
use ComposerStore\Tests\Support\Files;

final class ConcurrencyTest extends IntegrationTestCase
{
    private const REQUIRE = ['acme/alpha' => '2.0.0', 'acme/beta' => '1.0.0', 'acme/gamma' => '1.0.0'];

    /**
     * Both runs race to create the same entries: whichever loses must use the winner's entry.
     * A few rounds, since one round may not collide.
     */
    public function testConcurrentInstallsShareOneStore(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $this->store = $this->work . '/store-' . $round;
            $projects = [
                $this->createProject("first-{$round}", self::REQUIRE),
                $this->createProject("second-{$round}", self::REQUIRE),
            ];

            // Separate Composer caches: Composer 2.2 races on creating cache directories and then falls
            // back to a git clone. The store is what this test shares.
            $runs = array_map(
                fn (string $project) => $this->startComposer($project, ['install'], env: [
                    'COMPOSER_CACHE_DIR' => $project . '-cache',
                ]),
                $projects
            );
            foreach ($runs as $run) {
                $result = $run->wait();
                $this->assertSame(0, $result->exitCode, $result->describe());
            }

            foreach ($projects as $project) {
                $this->assertLinkedFromStore($project, 'acme/alpha', '2.0.0');
                $this->assertLinkedFromStore($project, 'acme/beta', '1.0.0', copied: ['bin/beta']);
                $this->assertLinkedFromStore($project, 'acme/gamma', '1.0.0');
            }
            foreach (array_keys(self::REQUIRE) as $package) {
                $entries = glob($this->store . '/packages/' . $package . '/*') ?: [];
                $this->assertCount(1, $entries, "round {$round}: {$package} has more than one entry");
                $meta = StoreEntry::readMeta($entries[0]) ?? [];
                $this->assertSame(TreeHasher::hash($entries[0] . '/files'), $meta['tree_hash'] ?? null);
            }
            $this->assertSame([], glob($this->store . '/tmp/*'), "round {$round}: temp dirs were left behind");
        }
    }

    public function testInstallsWaitForStoreMaintenanceThenFallBackToAPlainInstall(): void
    {
        Files::makeDir($this->store);
        $maintenance = new StoreLock($this->store . '/.lock');
        $this->assertTrue($maintenance->acquireExclusive(0));
        $project = $this->createProject('app', ['acme/alpha' => '1.0.0']);

        try {
            $result = $this->runComposer($project, ['install'], env: ['COMPOSER_STORE_LOCK_TIMEOUT' => '1']);
        } finally {
            $maintenance->release();
        }

        $this->assertSame(0, $result->exitCode, $result->describe());
        $this->assertStringContainsString('waiting up to 1 seconds for the store lock', $result->output());
        $this->assertStringContainsString('could not lock the store', $result->output());
        $this->assertNotLinked($project, 'acme/alpha');
        $this->assertDirectoryDoesNotExist($this->store . '/packages/acme/alpha');

        // Once maintenance is over, installs use the store again.
        $next = $this->createProject('next', ['acme/alpha' => '1.0.0']);
        $this->composer($next, 'install');
        $this->assertLinkedFromStore($next, 'acme/alpha', '1.0.0');
    }
}

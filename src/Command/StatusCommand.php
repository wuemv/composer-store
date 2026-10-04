<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use Composer\Composer;
use Composer\Factory;
use ComposerStore\Config;
use ComposerStore\Link\Cloner;
use ComposerStore\Link\Device;
use ComposerStore\Link\Method;
use ComposerStore\Link\MethodChoice;
use ComposerStore\Mode;
use ComposerStore\Store\EntryStats;
use ComposerStore\Store\InstalledPackages;
use ComposerStore\Store\Store;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends StoreCommand
{
    protected function configure(): void
    {
        $this->setName('store:status')
            ->setDescription('Shows where the store is, what it holds and how much disk space it saves')
            ->setHelp(
                <<<'HELP'
                Shows the store's location, size and package count, and an estimate of the disk space
                it saves: the space the hard links from vendor/ directories would take as copies, plus
                the size of the package versions that registered projects cloned with reflinks.
                Inside a project, also shows whether that project links from the store, and how.
                HELP
            );
        $this->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $store = $this->store();

        $stats = new EntryStats();
        $sizes = [];
        $packages = [];
        $entries = $store->entries();
        foreach ($entries as $entry) {
            $entryStats = EntryStats::of($entry->filesDir());
            $stats = $stats->add($entryStats);
            $sizes[$entry->path] = $entryStats->bytes;
            $packages[$entry->name] = true;
        }
        $projects = $store->projects()->projects();
        $missingProjects = count(array_filter(array_keys($projects), static fn (string $dir): bool => !is_dir($dir)));
        [$clones, $cloningProjects, $clonedBytes] = $this->clones($store, $projects, $sizes);
        $savedBytes = $stats->savedBytes + $clonedBytes;
        $tempDirs = count($store->tempDirs());
        $composer = $this->project();
        $project = $composer === null ? null : $this->projectStatus($composer, $store);

        if ($json) {
            $this->writeJson($output, [
                'store' => $store->root(),
                'exists' => is_dir($store->root()),
                'entries' => count($entries),
                'packages' => count($packages),
                'files' => $stats->files,
                'bytes' => $stats->bytes,
                'linked-files' => $stats->linkedFiles,
                'links' => $stats->links,
                'clones' => $clones,
                'cloned-bytes' => $clonedBytes,
                'saved-bytes' => $savedBytes,
                'projects' => count($projects),
                'missing-projects' => $missingProjects,
                'temp-dirs' => $tempDirs,
                'project' => $project,
            ]);

            return 0;
        }

        $rows = [
            'Store' => self::escape($store->root()) . (is_dir($store->root()) ? '' : ' (not created yet)'),
            'Packages' => self::count(count($packages), 'package') . ', '
                . self::count(count($entries), 'version'),
            'Size' => self::size($stats->bytes) . ' in ' . self::count($stats->files, 'file'),
            'Linked' => self::count($stats->linkedFiles, 'file') . ', by '
                . self::count($stats->links, 'hard link') . ' from vendor/ directories',
        ];
        if ($clones > 0) {
            $rows['Cloned'] = self::count($clones, 'package version') . ', by '
                . self::count($cloningProjects, 'project') . ' using reflinks';
        }
        $rows += [
            'Saved' => self::size($savedBytes) . ($clones > 0 ? ' (estimated)' : ''),
            'Projects' => self::count(count($projects), 'project') . ' registered'
                . ($missingProjects > 0 ? sprintf(', %d no longer there', $missingProjects) : ''),
        ];
        if ($tempDirs > 0) {
            $rows['Temp dirs'] = self::count($tempDirs, 'directory', 'directories')
                . ' left by interrupted installs';
        }
        if ($project !== null) {
            $rows['This project'] = self::describeProject($project);
            $rows['Settings'] = sprintf(
                'mode %s, read-only %s',
                $project['mode'],
                $project['read-only'] ? 'on' : 'off'
            );
        }
        foreach ($rows as $label => $value) {
            $output->writeln(sprintf('%-13s %s', $label . ':', $value));
        }
        if ($missingProjects > 0 || $tempDirs > 0) {
            $output->writeln('Run <comment>composer store:prune</comment> to clean up.');
        }

        return 0;
    }

    /**
     * The store entries that registered projects cloned with reflinks, as listed in their
     * installed.json. An estimate: a clone stops sharing the blocks of a file once it is edited.
     *
     * @param array<string, array{vendor-dir: string, last-install: string, method: ?Method}> $projects
     * @param array<string, int>                                                            $sizes by entry path
     *
     * @return array{int, int, int} clones, projects that cloned, bytes the clones share with the store
     */
    private function clones(Store $store, array $projects, array $sizes): array
    {
        $clones = $cloningProjects = $bytes = 0;
        foreach ($projects as $dir => $project) {
            if ($project['method'] !== Method::Reflink || !is_dir($dir)) {
                continue;
            }
            $cloned = 0;
            foreach (InstalledPackages::read($project['vendor-dir']) as $package) {
                $path = $store->entryPath($package['name'], $package['version'], $package['reference']);
                if ($package['source'] === 'dist' && isset($sizes[$path])) {
                    $cloned++;
                    $bytes += $sizes[$path];
                }
            }
            $clones += $cloned;
            $cloningProjects += $cloned > 0 ? 1 : 0;
        }

        return [$clones, $cloningProjects, $bytes];
    }

    /**
     * Whether the project links from the store and how, as far as can be told without installing.
     *
     * @return array{dir: string, vendor-dir: string, mode: string, read-only: bool, links: bool,
     *     method: ?string, reason: string}
     */
    private function projectStatus(Composer $composer, Store $store): array
    {
        $config = Config::fromComposer($composer);
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $vendorDir = is_string($vendorDir) ? $vendorDir : 'vendor';
        $composerJson = realpath(Factory::getComposerFile());

        $method = null;
        $reason = '';
        if ($config->mode === Mode::Copy) {
            $reason = 'mode is copy';
        } elseif (!Device::same($store->root(), $vendorDir)) {
            $reason = 'the store is on another filesystem than ' . $vendorDir;
        } elseif (is_dir($store->root() . '/tmp')) {
            $choice = MethodChoice::make($config->mode, $store->root(), new Cloner());
            $method = $choice->method?->value;
            $reason = $choice->reason;
        }
        // Without a store yet, the first install picks the method.

        return [
            'dir' => $composerJson === false ? (string) getcwd() : dirname($composerJson),
            'vendor-dir' => $vendorDir,
            'mode' => $config->mode->value,
            'read-only' => $config->readOnly && PHP_OS_FAMILY !== 'Windows',
            'links' => $reason === '',
            'method' => $method,
            'reason' => $reason,
        ];
    }

    /**
     * @param array{links: bool, method: ?string, reason: string} $project
     */
    private static function describeProject(array $project): string
    {
        if (!$project['links']) {
            return '<comment>does not link from the store: ' . self::escape($project['reason']) . '</comment>';
        }
        $method = Method::tryFrom((string) $project['method']);

        return $method === null
            ? '<info>links from the store</info> (its first install picks reflinks or hard links)'
            : '<info>links from the store with ' . $method->describe() . '</info>';
    }
}

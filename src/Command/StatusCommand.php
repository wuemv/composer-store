<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use Composer\Composer;
use Composer\Factory;
use ComposerStore\Config;
use ComposerStore\Mode;
use ComposerStore\Store\EntryStats;
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
                Shows the store's location, size and package count, and the disk space saved by the
                hard links from vendor/ directories: the space those links would take as separate
                copies. Inside a project, also shows whether that project links from the store.
                HELP
            );
        $this->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $store = $this->store();

        $stats = new EntryStats();
        $packages = [];
        $entries = $store->entries();
        foreach ($entries as $entry) {
            $stats = $stats->add(EntryStats::of($entry->filesDir()));
            $packages[$entry->name] = true;
        }
        $projects = array_keys($store->projects()->projects());
        $missingProjects = count(array_filter($projects, static fn (string $dir): bool => !is_dir($dir)));
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
                'saved-bytes' => $stats->savedBytes,
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
                . self::count($stats->links, 'link') . ' from vendor/ directories',
            'Saved' => self::size($stats->savedBytes),
            'Projects' => self::count(count($projects), 'project') . ' registered'
                . ($missingProjects > 0 ? sprintf(', %d no longer there', $missingProjects) : ''),
        ];
        if ($tempDirs > 0) {
            $rows['Temp dirs'] = self::count($tempDirs, 'directory', 'directories')
                . ' left by interrupted installs';
        }
        if ($project !== null) {
            $rows['This project'] = $project['links']
                ? '<info>links from the store</info>'
                : '<comment>does not link from the store: ' . self::escape($project['reason']) . '</comment>';
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
     * Whether the project links from the store, as far as can be told without installing.
     *
     * @return array{dir: string, vendor-dir: string, mode: string, read-only: bool, links: bool, reason: string}
     */
    private function projectStatus(Composer $composer, Store $store): array
    {
        $config = Config::fromComposer($composer);
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $vendorDir = is_string($vendorDir) ? $vendorDir : 'vendor';
        $composerJson = realpath(Factory::getComposerFile());

        $reason = '';
        if ($config->mode === Mode::Copy) {
            $reason = 'mode is copy';
        } elseif ($config->mode === Mode::Reflink) {
            $reason = 'reflink mode is not implemented yet';
        } elseif (self::device($store->root()) !== self::device($vendorDir)) {
            $reason = 'the store is on another filesystem than ' . $vendorDir;
        }

        return [
            'dir' => $composerJson === false ? (string) getcwd() : dirname($composerJson),
            'vendor-dir' => $vendorDir,
            'mode' => $config->mode->value,
            'read-only' => $config->readOnly && PHP_OS_FAMILY !== 'Windows',
            'links' => $reason === '',
            'reason' => $reason,
        ];
    }

    /**
     * The device of a path, or of its closest existing parent: where it would be created.
     */
    private static function device(string $path): ?int
    {
        while (!file_exists($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }
            $path = $parent;
        }
        $stat = @stat($path);

        return $stat === false ? null : $stat['dev'];
    }
}

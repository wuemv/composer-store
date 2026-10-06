<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use ComposerStore\Link\CloneInfo;
use ComposerStore\Monitor\ProjectUsage;
use ComposerStore\Monitor\Snapshot;
use ComposerStore\Monitor\UsageScanner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MonitorCommand extends StoreCommand
{
    protected function configure(): void
    {
        $this->setName('store:monitor')
            ->setDescription('Shows, live, the disk space the projects in a directory take, with and without the store')
            ->setHelp(
                <<<'HELP'
                Watches the Composer projects in a directory, and the directories up to three levels
                below it, while you install or remove packages in them. For each project it shows how
                many packages come from the store, what its vendor/ takes as Finder and du count it
                (each file at full size), and what it takes of its own: data no other file shares. The
                totals compare that with what the same vendor/ directories would take without the store,
                and the disk line shows the free space, measured, changing since the monitor started.

                It measures the files themselves, so a project installed without the plugin shows as
                copies. On macOS, telling clones from copies needs PHP's FFI extension; without it,
                those figures show as ?. It never takes the store lock, so installs do not wait for it.

                Without a directory, it asks for one, suggesting ~/Developer when there is one.
                HELP
            );
        $this->addArgument(
            'dir',
            InputArgument::OPTIONAL,
            'The directory of the projects to watch; without it, the command asks, suggesting ~/Developer'
        );
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between refreshes', '2');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Measure once and exit');
        $this->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $dir = $this->watchedDir($input);
        if ($dir === null) {
            return 1;
        }
        $interval = self::interval($input);
        $once = $json || $input->getOption('once') === true;
        $scanner = new UsageScanner($this->store(), CloneInfo::load());
        $progress = $this->progress();
        $startFree = null;
        $startTime = microtime(true);

        for ($frame = 0;; $frame++) {
            $started = microtime(true);
            $snapshot = $scanner->snapshot($dir, $frame === 0 ? $progress : null);
            if ($json) {
                $this->writeJson($output, $snapshot->toArray());

                return 0;
            }
            if ($once) {
                $output->writeln($this->render($snapshot, null));

                return 0;
            }
            $startFree ??= $snapshot->freeBytes;
            if ($output->isDecorated()) {
                $output->write("\033[H\033[2J"); // cursor home, clear the screen
            } elseif ($frame > 0) {
                $output->writeln('');
            }
            $output->writeln(sprintf(
                'Watching for %s, every %g s. Ctrl+C stops.',
                gmdate('H:i:s', (int) ($started - $startTime)),
                $interval
            ));
            $output->writeln($this->render($snapshot, $startFree));
            usleep((int) max(0, ($interval - (microtime(true) - $started)) * 1e6));
        }
    }

    /**
     * @return list<string>
     */
    private function render(Snapshot $snapshot, ?int $startFree): array
    {
        $lines = ['Projects in <info>' . self::escape($snapshot->dir) . '</info>', ''];
        $disk = $snapshot->freeBytes === null ? 'free space unknown' : self::size($snapshot->freeBytes) . ' free';
        if ($startFree !== null && $snapshot->freeBytes !== null && $startFree !== $snapshot->freeBytes) {
            $change = $startFree - $snapshot->freeBytes;
            $disk .= sprintf(
                ', %s %s since the monitor started',
                self::size(abs($change)),
                $change > 0 ? 'used' : 'freed'
            );
        }
        $lines[] = 'Disk:    ' . $disk;
        $store = self::size($snapshot->storeBytes) . ' in ' . self::count($snapshot->storeEntries, 'package version');
        if ($snapshot->storeUsedBytes !== null && $snapshot->projects !== []) {
            $store .= ', ' . self::size($snapshot->storeUsedBytes) . ' of it used by these projects';
        }
        $lines[] = 'Store:   ' . $store;
        if (!$snapshot->sameFilesystem) {
            $lines[] = '<comment>The store is on another filesystem than this directory: installs here copy.</comment>';
        }
        $lines[] = '';
        if ($snapshot->projects === []) {
            $lines[] = 'No Composer projects here yet. Create or copy one here, then run composer install in it.';

            return $lines;
        }

        $rows = [['Project', 'Packages', 'From the store', 'Finder size', 'Own data']];
        foreach ($snapshot->projects as $project) {
            $rows[] = [
                self::name($snapshot->dir, $project),
                number_format($project->packages),
                self::fromStore($project->fromStore, $project->linkedBy()),
                self::size($project->vendorBytes),
                $project->ownBytes === null ? '?' : self::size($project->ownBytes),
            ];
        }
        $own = $snapshot->ownBytes();
        $rows[] = [
            'Total',
            number_format($snapshot->packages()),
            self::fromStore($snapshot->fromStore(), null),
            self::size($snapshot->vendorBytes()),
            $own === null ? '?' : self::size($own),
        ];
        array_push($lines, ...self::table($rows));
        $lines[] = '';

        $lines[] = 'Without the store, these vendor/ directories take ' . self::size($snapshot->vendorBytes()) . '.';
        $withStore = $snapshot->withStoreBytes();
        $saved = $snapshot->savedBytes();
        if ($withStore === null || $saved === null || $own === null || $snapshot->storeUsedBytes === null) {
            $lines[] = '<comment>Clones look like copies here: '
                . (PHP_OS_FAMILY === 'Darwin'
                    ? 'run the monitor with a PHP that has the FFI extension to see them.'
                    : 'this platform cannot tell them apart yet.')
                . '</comment>';

            return $lines;
        }
        $lines[] = sprintf(
            'With the store, they take %s: %s of store data they share, plus %s of their own.',
            self::size($withStore),
            self::size($snapshot->storeUsedBytes),
            self::size($own)
        );
        $vendorBytes = $snapshot->vendorBytes();
        $lines[] = sprintf(
            '<info>Saved: %s%s</info>',
            self::size($saved),
            $vendorBytes > 0 ? sprintf(' (%d%%)', (int) round(100 * $saved / $vendorBytes)) : ''
        );

        return $lines;
    }

    /**
     * Left-aligned first column, right-aligned numbers.
     *
     * @param list<list<string>> $rows
     *
     * @return list<string>
     */
    private static function table(array $rows): array
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen($cell));
            }
        }
        $lines = [];
        foreach ($rows as $r => $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = $i === 0 ? str_pad($cell, $widths[$i]) : str_pad($cell, $widths[$i], ' ', STR_PAD_LEFT);
            }
            $line = self::escape(implode('   ', $cells));
            $lines[] = $r === 0 || $r === count($rows) - 1 ? '<comment>' . $line . '</comment>' : $line;
        }

        return $lines;
    }

    private static function name(string $dir, ProjectUsage $project): string
    {
        return $project->dir === $dir ? basename($dir) : substr($project->dir, strlen($dir) + 1);
    }

    private static function fromStore(?int $count, ?string $linkedBy): string
    {
        if ($count === null) {
            return '?';
        }
        $how = match ($linkedBy) {
            'reflink' => ' (reflinks)',
            'hardlink' => ' (hard links)',
            'mixed' => ' (both)',
            default => '',
        };

        return number_format($count) . $how;
    }
}

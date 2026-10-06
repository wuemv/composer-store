<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use ComposerStore\Link\CloneInfo;
use ComposerStore\Monitor\DashboardServer;
use ComposerStore\Monitor\UsageScanner;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DashboardCommand extends StoreCommand
{
    protected function configure(): void
    {
        $this->setName('store:dashboard')
            ->setDescription('Opens live charts of the disk space the projects in a directory take, in your browser')
            ->setHelp(
                <<<'HELP'
                Measures the Composer projects in a directory as store:monitor does, and serves a page
                with live charts of them on 127.0.0.1, which it opens in your browser: the space saved,
                the projects' vendor/ directories with and without the store over time, and each
                project's size against the data it holds of its own.

                It measures only while it runs, every --interval seconds. Ctrl+C stops the measuring
                and the page together; nothing keeps running in the background.

                Without a directory, it asks for one, suggesting ~/Developer when there is one.
                HELP
            );
        $this->addArgument(
            'dir',
            InputArgument::OPTIONAL,
            'The directory of the projects to watch; without it, the command asks, suggesting ~/Developer'
        );
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between measurements', '2');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port on 127.0.0.1; by default, any free one', '0');
        $this->addOption('no-open', null, InputOption::VALUE_NONE, 'Print the address without opening a browser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->watchedDir($input);
        if ($dir === null) {
            return 1;
        }
        $interval = self::interval($input);
        $port = $input->getOption('port');
        if (!is_string($port) || preg_match('{^\d{1,5}$}', $port) !== 1 || (int) $port > 65535) {
            throw new \InvalidArgumentException('The port is a number from 0 to 65535, 0 for any free one');
        }

        $scanner = new UsageScanner($this->store(), CloneInfo::load());
        // The slow first measurement, before the page opens: the server's own first one reuses it.
        $scanner->snapshot($dir, $this->progress());
        $server = new DashboardServer($scanner, $dir, $interval);
        try {
            $url = $server->start((int) $port);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return 1;
        }
        $output->writeln(sprintf('Dashboard for %s: <info>%s</info>', self::escape($dir), $url));
        $this->getIO()->writeError(sprintf('Measuring every %g s while this runs. Ctrl+C stops.', $interval));
        if ($input->getOption('no-open') !== true) {
            self::openBrowser($url);
        }

        $server->serve();
    }

    /**
     * Best effort: the address is printed either way.
     */
    private static function openBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['open', $url],
            'Windows' => ['cmd', '/c', 'start', '', $url],
            default => ['xdg-open', $url],
        };
        $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}

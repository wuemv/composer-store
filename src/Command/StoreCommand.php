<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Factory;
use ComposerStore\Config;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreLock;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base for the store:* commands. They work inside a project and outside of one: the store location
 * only depends on COMPOSER_STORE_DIR and the Composer home.
 *
 * Results go to stdout, so `--format=json` can be piped; messages about the run go to stderr.
 */
abstract class StoreCommand extends BaseCommand
{
    protected function addFormatOption(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function wantsJson(InputInterface $input): bool
    {
        $format = $input->getOption('format');
        if ($format !== 'text' && $format !== 'json') {
            throw new \InvalidArgumentException(sprintf('Unknown format %s, use text or json', json_encode($format)));
        }

        return $format === 'json';
    }

    /**
     * @param array<mixed> $data
     */
    protected function writeJson(OutputInterface $output, array $data): void
    {
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $output->writeln($json, OutputInterface::OUTPUT_RAW);
    }

    protected function store(): Store
    {
        $config = $this->project()?->getConfig() ?? Factory::createConfig($this->getIO());
        $home = $config->get('home');

        return new Store(Config::storeDir(is_string($home) ? $home : ''));
    }

    /**
     * The project the command runs in, or null outside of a project.
     */
    protected function project(): ?Composer
    {
        // Composer 2.3 added tryComposer() for this, but older releases only have getComposer().
        return $this->getComposer(false);
    }

    /**
     * Takes the store lock, telling the user when it has to wait for another process to let go.
     * Check isHeld() on the result.
     *
     * @param string $waitingFor what the command waits for, e.g. "running installs to finish"
     */
    protected function lockStore(Store $store, bool $exclusive, string $waitingFor): StoreLock
    {
        $lock = $store->lock();
        $timeout = StoreLock::timeout();
        $onWait = function () use ($timeout, $waitingFor): void {
            $this->getIO()->writeError(sprintf('<info>Waiting up to %g seconds for %s</info>', $timeout, $waitingFor));
        };
        if ($exclusive) {
            $lock->acquireExclusive($timeout, $onWait);
        } else {
            $lock->acquireShared($timeout, $onWait);
        }

        return $lock;
    }

    protected function error(string $message): void
    {
        $this->getIO()->writeError('<error>' . self::escape($message) . '</error>');
    }

    /**
     * Text from outside the program (paths, versions) printed through the console formatter.
     */
    protected static function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }

    protected static function size(int $bytes): string
    {
        foreach (['GiB' => 1 << 30, 'MiB' => 1 << 20, 'KiB' => 1 << 10] as $unit => $factor) {
            if ($bytes >= $factor) {
                return sprintf('%.1f %s', $bytes / $factor, $unit);
            }
        }

        return $bytes . ' B';
    }

    protected static function count(int $count, string $singular, ?string $plural = null): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }
}

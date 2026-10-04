<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use ComposerStore\Store\PrunePlan;
use ComposerStore\Store\Pruner;
use ComposerStore\Store\Store;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PruneCommand extends StoreCommand
{
    protected function configure(): void
    {
        $this->setName('store:prune')
            ->setDescription('Deletes the store entries no project uses (lists them unless --force)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete them, instead of only listing them')
            ->setHelp(
                <<<'HELP'
                An entry is in use while a vendor/ directory hard-links any of its files, or while a
                project in the store's projects.json lists that package version in its
                vendor/composer/installed.json. Everything else is unused: deleting it does not change
                any project. Also deletes the temp directories of interrupted installs, and forgets the
                registered projects whose directory is gone.

                Without --force, only lists what it would delete. With --force, it waits for running
                Composer installs to finish, and installs that start meanwhile wait for it.
                HELP
            );
        $this->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $force = (bool) $input->getOption('force');
        $store = $this->store();
        if (!is_dir($store->root())) {
            return $this->report($output, $json, $store, new PrunePlan([], [], []), $force, []);
        }

        $pruner = new Pruner($store);
        if (!$force) {
            return $this->report($output, $json, $store, $pruner->plan(), $force, []);
        }

        // Installs hold the lock while they link: none may run between planning and deleting.
        $lock = $this->lockStore($store, true, 'running Composer installs to finish');
        if (!$lock->isHeld()) {
            $this->error(sprintf(
                'Could not lock the store at %s (%s), nothing was deleted. Composer installs use the store'
                . ' while they run: try again when they are done.',
                $store->root(),
                $lock->failure()
            ));

            return 1;
        }
        try {
            $plan = $pruner->plan();
            $failures = $pruner->prune($plan);
        } finally {
            $lock->release();
        }

        return $this->report($output, $json, $store, $plan, $force, $failures);
    }

    /**
     * @param list<string> $failures
     */
    private function report(
        OutputInterface $output,
        bool $json,
        Store $store,
        PrunePlan $plan,
        bool $deleted,
        array $failures,
    ): int {
        $status = $failures === [] ? 0 : 1;
        if ($json) {
            $this->writeJson($output, [
                'store' => $store->root(),
                'deleted' => $deleted,
                'entries' => array_map(static fn (array $unused): array => [
                    'name' => $unused['entry']->name,
                    'version' => $unused['entry']->version,
                    'reference' => $unused['entry']->reference,
                    'path' => $unused['entry']->path,
                    'bytes' => $unused['stats']->bytes,
                ], $plan->entries),
                'temp-dirs' => $plan->tempDirs,
                'missing-projects' => $plan->missingProjects,
                'bytes' => $plan->bytes(),
                'failures' => $failures,
            ]);

            return $status;
        }

        if ($plan->isEmpty()) {
            $output->writeln(sprintf('<info>Nothing to prune in %s</info>', self::escape($store->root())));

            return $status;
        }

        $sections = [
            'Unused entries' => array_map(static fn (array $unused): string => sprintf(
                '%s %s (%s)',
                $unused['entry']->name,
                $unused['entry']->version !== '' ? $unused['entry']->version : '(unknown version)',
                self::size($unused['stats']->bytes)
            ), $plan->entries),
            'Temp dirs left by interrupted installs' => $plan->tempDirs,
            'Registered projects that are gone' => $plan->missingProjects,
        ];
        foreach ($sections as $heading => $lines) {
            if ($lines !== []) {
                $output->writeln('<comment>' . $heading . ':</comment>');
                foreach ($lines as $line) {
                    $output->writeln('  ' . self::escape($line));
                }
            }
        }

        $deletions = [];
        if ($plan->entries !== []) {
            $deletions[] = self::count(count($plan->entries), 'unused entry', 'unused entries');
        }
        if ($plan->tempDirs !== []) {
            $deletions[] = self::count(count($plan->tempDirs), 'temp dir');
        }
        $actions = [];
        if ($deletions !== []) {
            $verb = $deleted ? 'deleted' : 'delete';
            $actions[] = sprintf('%s %s (%s)', $verb, implode(' and ', $deletions), self::size($plan->bytes()));
        }
        if ($plan->missingProjects !== []) {
            $actions[] = ($deleted ? 'forgot ' : 'forget ') . self::count(count($plan->missingProjects), 'project');
        }
        $summary = implode(', and ', $actions);

        if (!$deleted) {
            $output->writeln(sprintf(
                'Would %s. Run <comment>composer store:prune --force</comment> to do it.',
                $summary
            ));
        } elseif ($failures === []) {
            $output->writeln('<info>' . ucfirst($summary) . '.</info>');
        } else {
            foreach ($failures as $failure) {
                $this->error($failure);
            }
            $output->writeln('<comment>Some of it could not be deleted, see the errors above.</comment>');
        }

        return $status;
    }
}

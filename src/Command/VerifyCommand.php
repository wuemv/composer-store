<?php

declare(strict_types=1);

namespace ComposerStore\Command;

use ComposerStore\NamePatterns;
use ComposerStore\Store\EntryCheck;
use ComposerStore\Store\EntryStatus;
use ComposerStore\Store\Verifier;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class VerifyCommand extends StoreCommand
{
    /** Modified files listed per changed entry; -v lists them all. */
    private const FILES_SHOWN = 10;

    protected function configure(): void
    {
        $this->setName('store:verify')
            ->setDescription('Re-hashes store entries and reports the ones that changed')
            ->addArgument(
                'packages',
                InputArgument::IS_ARRAY,
                'Package names to verify, where * matches any characters (default: all)'
            )
            ->setHelp(
                <<<'HELP'
                Compares every store entry with the tree hash taken when it was stored. An entry
                changes when one of its files is edited in place through a vendor/ directory that
                links it, which changes that file in every project linking it.

                To repair an entry, delete its directory and run "composer reinstall <package>" in
                each project that uses it. Exits with 1 when an entry changed or is invalid.
                HELP
            );
        $this->addFormatOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $store = $this->store();
        $packages = $input->getArgument('packages');
        $patterns = new NamePatterns(array_values(array_filter(is_array($packages) ? $packages : [], 'is_string')));

        $checks = [];
        if (is_dir($store->root())) {
            // Keeps store:prune from deleting entries while they are hashed.
            $lock = $this->lockStore($store, false, 'store:prune to finish');
            if (!$lock->isHeld()) {
                $this->error(sprintf('Could not lock the store at %s (%s)', $store->root(), $lock->failure()));

                return 1;
            }
            $verifier = new Verifier($store);
            foreach ($store->entries() as $entry) {
                if ($patterns->isEmpty() || $patterns->matches($entry->name)) {
                    $checks[] = $verifier->check($entry);
                }
            }
            $lock->release();
        }

        $counts = array_fill_keys(array_map(static fn (EntryStatus $s): string => $s->value, EntryStatus::cases()), 0);
        foreach ($checks as $check) {
            $counts[$check->status->value]++;
        }
        $failed = $counts[EntryStatus::Changed->value] + $counts[EntryStatus::Invalid->value] > 0;

        if ($json) {
            $this->writeJson($output, ['store' => $store->root()] + $counts + [
                'entries' => array_map(static fn (EntryCheck $check): array => [
                    'name' => $check->entry->name,
                    'version' => $check->entry->version,
                    'reference' => $check->entry->reference,
                    'path' => $check->entry->path,
                    'status' => $check->status->value,
                    'details' => $check->details,
                ], $checks),
            ]);

            return $failed ? 1 : 0;
        }

        $verbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        foreach ($checks as $check) {
            if ($check->isProblem() || ($verbose && $check->status === EntryStatus::Unhashed)) {
                $this->describe($output, $check, $verbose);
            }
        }

        $summary = sprintf(
            'Verified %s: %d ok, %d changed, %d invalid',
            self::count(count($checks), 'entry', 'entries'),
            $counts[EntryStatus::Ok->value],
            $counts[EntryStatus::Changed->value],
            $counts[EntryStatus::Invalid->value]
        );
        if ($counts[EntryStatus::Unhashed->value] > 0) {
            $summary .= sprintf(', %d without a tree hash to check', $counts[EntryStatus::Unhashed->value]);
        }
        $output->writeln(($failed ? '<error>' : '<info>') . $summary . ($failed ? '</error>' : '</info>'));
        if ($failed) {
            $output->writeln(sprintf(
                'To repair an entry, delete its directory and run <comment>%s</comment> in each project that uses it.',
                self::escape('composer reinstall <package>')
            ));
        }

        return $failed ? 1 : 0;
    }

    private function describe(OutputInterface $output, EntryCheck $check, bool $verbose): void
    {
        $entry = $check->entry;
        $label = match ($check->status) {
            EntryStatus::Changed => '<error>changed</error>',
            EntryStatus::Invalid => '<error>invalid</error>',
            default => '<comment>not checked</comment>, it has no tree hash',
        };
        $output->writeln(sprintf(
            '%s %s: %s',
            self::escape($entry->name),
            self::escape($entry->version !== '' ? $entry->version : '(unknown version)'),
            $label
        ));
        $output->writeln('  ' . self::escape($entry->path));

        if ($check->status === EntryStatus::Invalid) {
            foreach ($check->details as $reason) {
                $output->writeln('  ' . self::escape($reason));
            }

            return;
        }
        if ($check->status === EntryStatus::Changed) {
            $shown = $verbose ? $check->details : array_slice($check->details, 0, self::FILES_SHOWN);
            foreach ($shown as $file) {
                $output->writeln('  modified: ' . self::escape($file));
            }
            $hidden = count($check->details) - count($shown);
            if ($hidden > 0) {
                $output->writeln(sprintf('  and %s (-v lists them)', self::count($hidden, 'more file')));
            }
        }
    }
}

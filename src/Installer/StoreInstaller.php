<?php

declare(strict_types=1);

namespace ComposerStore\Installer;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Downloader\ArchiveDownloader;
use Composer\Downloader\DownloadManager;
use Composer\Factory;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Util\ProcessExecutor;
use ComposerStore\Config;
use ComposerStore\Link\Device;
use ComposerStore\Link\LinkException;
use ComposerStore\Link\Linker;
use ComposerStore\Link\Method;
use ComposerStore\Link\MethodChoice;
use ComposerStore\Mode;
use ComposerStore\Store\PublishResult;
use ComposerStore\Store\Store;
use ComposerStore\Store\StoreEntry;
use ComposerStore\Store\StoreException;
use ComposerStore\Store\StoreLock;
use React\Promise\PromiseInterface;
use Symfony\Component\Process\Process;

/**
 * Installs `library` and `project` packages from the store: a version is extracted into the store once,
 * and each project's vendor/ gets reflinks (copy-on-write clones) or hard links to it. Anything else
 * (source installs, path repositories, non-archive dists, excluded or patched packages, store on
 * another filesystem) is left to Composer's normal install.
 *
 * Composer still downloads the archive first: its download step decides between dist and source,
 * and that decision is only visible afterwards, through the package's installation source.
 */
final class StoreInstaller extends LibraryInstaller
{
    private const ARCHIVE_TYPES = ['zip', 'tar'];

    /**
     * The package types Composer installs into vendor/<name> with its default installer. Other types
     * belong to installers of their own (composer/installers and the like), or have no files.
     */
    private const PACKAGE_TYPES = ['library', 'project'];

    private readonly DownloadManager $downloads;

    /** Read when needed: Composer 2.0 activates plugins before it attaches the locker. */
    private readonly Composer $project;

    private readonly StoreLock $lock;

    private ?bool $storeUsable = null;

    private bool $readOnly = false;

    private Method $method = Method::Hardlink;

    /** Composer's process runner, for clones: it runs commands alongside each other. */
    private ?ProcessExecutor $process = null;

    private ?SkipRules $skipRules = null;

    public function __construct(
        IOInterface $io,
        Composer $composer,
        private readonly Config $config,
        private readonly Store $store,
        private readonly Linker $linker = new Linker(),
    ) {
        parent::__construct($io, $composer, 'library');
        $this->downloads = $composer->getDownloadManager();
        $this->project = $composer;
        $this->lock = $store->lock();
    }

    /**
     * @param string $packageType untyped like in Composer 2.0, where the parent declares no type
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return in_array($packageType, self::PACKAGE_TYPES, true);
    }

    /**
     * Lets go of the store lock this run holds, if any.
     */
    public function releaseStore(): void
    {
        $this->lock->release();
    }

    /**
     * @return PromiseInterface<mixed>|null
     */
    protected function installCode(PackageInterface $package)
    {
        $downloader = $this->storeDownloader($package);
        if ($downloader === null) {
            return parent::installCode($package);
        }

        $path = $this->getInstallPath($package);

        return $this->installFromStore($package, $downloader, $path, InstallOperation::format($package));
    }

    /**
     * @return PromiseInterface<mixed>|null
     */
    protected function updateCode(PackageInterface $initial, PackageInterface $target)
    {
        $downloader = $this->storeDownloader($target);
        if ($downloader === null) {
            return parent::updateCode($initial, $target);
        }

        // Removing the old version only unlinks it: the store keeps its entry.
        $initialPath = $this->getInstallPath($initial);
        if (!$this->filesystem->removeDirectory($initialPath)) {
            throw new \RuntimeException('Could not completely delete ' . $initialPath . ', aborting.');
        }
        $path = $this->getInstallPath($target);

        return $this->installFromStore($target, $downloader, $path, UpdateOperation::format($initial, $target));
    }

    /**
     * @return PromiseInterface<mixed>
     */
    private function installFromStore(
        PackageInterface $package,
        ArchiveDownloader $downloader,
        string $path,
        string $operation,
    ): PromiseInterface {
        $entry = $this->store->entryFor($package);
        if ($entry === null) {
            return self::promise($this->downloads->install($package, $path));
        }

        if ($entry->isValid()) {
            $this->io->writeError(sprintf('  - %s: Linking from store', $operation));
            if ($this->readOnly) {
                // The entry may predate read-only mode.
                Store::makeReadOnly($entry->filesDir());
            }

            return $this->place($package, $entry, $path);
        }

        if ($entry->exists()) {
            // Same key, different content (or unreadable metadata): leave the entry alone.
            $this->warnMismatch($entry, $package);

            return self::promise($this->downloads->install($package, $path));
        }

        $this->io->writeError(sprintf('  - %s: Extracting archive into the store', $operation));
        $temp = $this->store->createTempDir();
        $extraction = self::promise($downloader->install($package, $temp . '/' . StoreEntry::FILES_DIR, false));

        return $extraction->then(
            function () use ($package, $entry, $temp, $path): ?PromiseInterface {
                try {
                    $result = $this->store->publish($temp, $entry, $this->metadata($package), $this->readOnly);
                } catch (StoreException $e) {
                    $this->warn($e->getMessage() . ', installing ' . $package->getPrettyName() . ' without the store');
                    $this->useExtractedCopy($temp, $path);

                    return null;
                }
                if ($result === PublishResult::Conflict) {
                    // Another process published different files under this key in the meantime.
                    $this->warnMismatch($entry, $package);
                    $this->useExtractedCopy($temp, $path);

                    return null;
                }
                if ($result === PublishResult::AlreadyPublished && $this->readOnly) {
                    // The process that won may not use read-only mode.
                    Store::makeReadOnly($entry->filesDir());
                }

                return $this->place($package, $entry, $path);
            },
            static function (\Throwable $reason) use ($temp): void {
                Store::removeTree($temp);

                throw $reason;
            }
        );
    }

    /**
     * Moves a package extracted for the store into vendor/ instead: it is exactly what a normal
     * install would have put there.
     */
    private function useExtractedCopy(string $temp, string $path): void
    {
        if (file_exists($path) || is_link($path)) {
            $this->filesystem->remove($path);
        }
        $this->filesystem->rename($temp . '/' . StoreEntry::FILES_DIR, $path);
        Store::removeTree($temp);
    }

    /**
     * Links a store entry into vendor/, copying instead when that fails.
     *
     * Clones are made by cp processes that Composer runs alongside each other, up to ten at once, as
     * it does for unzip: one package after the other, cloning is slow on APFS.
     *
     * @return PromiseInterface<mixed>
     */
    private function place(PackageInterface $package, StoreEntry $entry, string $path): PromiseInterface
    {
        if (file_exists($path) || is_link($path)) {
            // Left over from an interrupted run: Composer's own extraction would empty it too.
            $this->filesystem->remove($path);
        }

        $copyInstead = function (LinkException $e) use ($package, $entry, $path): void {
            $this->warn($e->getMessage() . ', copying ' . $package->getPrettyName() . ' from the store instead');
            $this->linker->copy($entry->filesDir(), $path);
        };
        if ($this->method === Method::Reflink && $this->process !== null) {
            return $this->linker->reflinkAsync($entry->filesDir(), $path, $this->runAsync(...))->then(
                null,
                static function (\Throwable $e) use ($copyInstead): void {
                    if (!$e instanceof LinkException) {
                        throw $e;
                    }
                    $copyInstead($e);
                }
            );
        }

        try {
            if ($this->method === Method::Reflink) {
                $this->linker->reflink($entry->filesDir(), $path);
            } else {
                $this->linker->link($entry->filesDir(), $path, $package->getBinaries());
            }
        } catch (LinkException $e) {
            $copyInstead($e);
        }

        return \React\Promise\resolve(null);
    }

    /**
     * Starts a command in the background. The promise rejects with a LinkException when it fails.
     *
     * @param list<string> $command
     *
     * @return PromiseInterface<mixed>
     */
    private function runAsync(array $command): PromiseInterface
    {
        $process = $this->process ?? throw new \LogicException('No process runner');
        $arguments = [];
        foreach ($command as $argument) {
            $arguments[] = ProcessExecutor::escape($argument);
        }
        // A command line rather than an array: Composer before 2.3 only runs those.
        $line = implode(' ', $arguments);

        return $process->executeAsync($line)->then(static function (Process $cp) use ($line): void {
            if (!$cp->isSuccessful()) {
                $error = trim((string) strtok($cp->getErrorOutput(), "\n"));
                $error = $error !== '' ? $error : 'exit code ' . $cp->getExitCode();
                throw new LinkException(sprintf('%s failed: %s', $line, $error));
            }
        });
    }

    /**
     * The archive downloader to extract the package into the store with, or null when the package
     * must be installed by Composer as usual.
     */
    private function storeDownloader(PackageInterface $package): ?ArchiveDownloader
    {
        if (
            $package->getInstallationSource() !== 'dist'
            || !in_array($package->getDistType(), self::ARCHIVE_TYPES, true)
            || $this->store->entryFor($package) === null
        ) {
            return null;
        }

        $reason = $this->skipRules()->reason($package);
        if ($reason !== null) {
            $name = $package->getPrettyName();
            $message = sprintf('    composer-store: %s is %s, installing it without the store', $name, $reason);
            $this->io->writeError($message, true, IOInterface::VERBOSE);

            return null;
        }

        $downloader = $this->downloads->getDownloaderForPackage($package);
        if (!$downloader instanceof ArchiveDownloader || !$this->storeIsUsable()) {
            return null;
        }

        return $downloader;
    }

    private function skipRules(): SkipRules
    {
        return $this->skipRules ??= new SkipRules(
            $this->config->exclude,
            PatchedPackages::find($this->project->getPackage(), $this->projectDir(), $this->projectPackages())
        );
    }

    /**
     * Every package of the project, installed or about to be: any of them may declare patches.
     * Composer writes the lock file before it installs anything, so it holds the packages to come.
     *
     * @return list<PackageInterface>
     */
    private function projectPackages(): array
    {
        $packages = array_values($this->project->getRepositoryManager()->getLocalRepository()->getPackages());
        $locker = $this->project->getLocker();
        try {
            if (!$locker->isLocked()) {
                return $packages;
            }
        } catch (\Exception) {
            return $packages;
        }
        foreach ([true, false] as $withDev) {
            try {
                $locked = $locker->getLockedRepository($withDev)->getPackages();

                return array_merge($packages, array_values($locked));
            } catch (\Exception) {
                // A lock file without dev packages: try again without them.
            }
        }

        return $packages;
    }

    private function projectDir(): string
    {
        $composerJson = realpath(Factory::getComposerFile());
        if ($composerJson !== false) {
            return dirname($composerJson);
        }
        $cwd = getcwd();

        return $cwd === false ? '.' : $cwd;
    }

    /**
     * Whether this run can link from the store at all. Decided once, on the first package.
     */
    private function storeIsUsable(): bool
    {
        return $this->storeUsable ??= $this->checkStore();
    }

    private function checkStore(): bool
    {
        if ($this->config->mode === Mode::Copy) {
            return false;
        }

        try {
            $root = $this->store->initialize();
        } catch (StoreException $e) {
            $this->warn($e->getMessage() . ', installing packages without the store');

            return false;
        }

        $this->initializeVendorDir();
        if (!Device::same($root, $this->vendorDir)) {
            $this->warn(sprintf(
                'the store (%s) is not on the same filesystem as %s, so packages are copied as usual. '
                . 'Set COMPOSER_STORE_DIR to a directory on that filesystem to share them.',
                $root,
                $this->vendorDir
            ));

            return false;
        }

        if (!$this->lockStore($root)) {
            return false;
        }
        // Probed under the lock: store:prune empties tmp/.
        $choice = MethodChoice::make($this->config->mode, $root, $this->linker->cloner());
        if ($choice->method === null) {
            $this->lock->release();
            $this->warn(sprintf(
                'mode is reflink, but %s, so packages are copied as usual. Mode auto would use hard links.',
                $choice->reason
            ));

            return false;
        }
        $this->method = $choice->method;
        $how = $this->method->describe();
        $this->process = null;
        if ($this->method === Method::Reflink) {
            // A clonefile(2) call takes about a millisecond: only cp is worth running beside the others.
            $inProcess = $this->linker->cloner()->clonesInProcess();
            $this->process = $inProcess ? null : $this->project->getLoop()->getProcessExecutor();
            $how .= $inProcess ? ', through clonefile(2)' : ', through cp';
        }
        $message = sprintf('    composer-store: linking from %s with %s', $root, $how);
        $this->io->writeError($message, true, IOInterface::VERBOSE);
        $this->registerProject();

        $this->readOnly = $this->config->readOnly;
        if ($this->readOnly && PHP_OS_FAMILY === 'Windows') {
            // Windows cannot delete a read-only file, and clearing the flag would clear it for every link.
            $this->warn('read-only mode is not supported on Windows yet, ignoring it');
            $this->readOnly = false;
        }

        return true;
    }

    /**
     * Takes the store lock shared for the rest of the run, waiting for store maintenance to finish.
     */
    private function lockStore(string $root): bool
    {
        $timeout = StoreLock::timeout();
        $waiting = function () use ($timeout): void {
            $message = sprintf('composer-store: waiting up to %g seconds for the store lock', $timeout);
            $this->io->writeError('<info>' . $message . '</info>');
        };
        if ($this->lock->acquireShared($timeout, $waiting)) {
            return true;
        }
        $this->warn(sprintf(
            'could not lock the store at %s (%s), installing packages without the store',
            $root,
            $this->lock->failure()
        ));

        return false;
    }

    /**
     * Records the project in projects.json for store:prune. Best effort: without it, prune still sees
     * the project's hard links.
     */
    private function registerProject(): void
    {
        try {
            $this->store->projects()->register($this->projectDir(), $this->vendorDir, $this->method);
        } catch (StoreException $e) {
            $this->io->writeError('    composer-store: ' . $e->getMessage(), true, IOInterface::VERBOSE);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(PackageInterface $package): array
    {
        return [
            'format' => StoreEntry::META_FORMAT,
            'name' => $package->getName(),
            'version' => $package->getPrettyVersion(),
            'reference' => $package->getDistReference(),
            'dist' => ['type' => $package->getDistType(), 'url' => self::publicUrl((string) $package->getDistUrl())],
            'created_at' => gmdate(DATE_ATOM),
        ];
    }

    /**
     * The dist URL without credentials or query string, since the store may be readable by others.
     */
    private static function publicUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return (string) preg_replace('{[?#].*$}', '', $url);
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
    }

    /**
     * Downloaders may return null instead of a promise in older Composer 2 releases.
     *
     * @return PromiseInterface<mixed>
     */
    private static function promise(mixed $result): PromiseInterface
    {
        return $result instanceof PromiseInterface ? $result : \React\Promise\resolve(null);
    }

    private function warnMismatch(StoreEntry $entry, PackageInterface $package): void
    {
        $name = $package->getPrettyName();
        $this->warn(sprintf('%s does not hold %s, installing it without the store', $entry->path, $name));
    }

    private function warn(string $message): void
    {
        $this->io->writeError('<warning>composer-store: ' . $message . '</warning>');
    }
}

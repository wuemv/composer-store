<?php

declare(strict_types=1);

namespace ComposerStore;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use ComposerStore\Installer\StoreInstaller;
use ComposerStore\Store\Store;

final class Plugin implements PluginInterface
{
    private ?StoreInstaller $installer = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $config = Config::fromComposer($composer);
        foreach ($config->warnings as $warning) {
            $io->writeError('<warning>composer-store: ' . $warning . '</warning>');
        }

        $this->installer = new StoreInstaller($io, $composer, $config, new Store($config->storeDir));
        $composer->getInstallationManager()->addInstaller($this->installer);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        if ($this->installer !== null) {
            $this->installer->releaseStore();
            $composer->getInstallationManager()->removeInstaller($this->installer);
            $this->installer = null;
        }
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }
}

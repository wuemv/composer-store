<?php

declare(strict_types=1);

namespace ComposerStore\Tools\GitHubDistShim;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PreFileDownloadEvent;

/**
 * Serves GitHub zipball dists from a local `git archive` of the same commit.
 *
 * Only meant for sandboxes that allow `git` over HTTPS to github.com but block
 * GitHub's archive endpoints. The Composer cache key is left unchanged, so the
 * cache and the installed vendor/ look exactly as they would without the shim.
 */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const ZIPBALL_URL = '{^https://api\.github\.com/repos/([^/]+)/([^/]+)/zipball/([^/?#]+)$}';

    private IOInterface $io;

    private ArchiveBuilder $builder;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
        $dir = getenv('GITHUB_DIST_SHIM_DIR');
        if ($dir === false || $dir === '') {
            $dir = $composer->getConfig()->get('cache-dir') . '/github-dist-shim';
        }
        $this->builder = new ArchiveBuilder($dir);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [PluginEvents::PRE_FILE_DOWNLOAD => 'onPreFileDownload'];
    }

    public function onPreFileDownload(PreFileDownloadEvent $event): void
    {
        if ($event->getType() !== 'package' || getenv('GITHUB_DIST_SHIM_DISABLE')) {
            return;
        }

        $url = $event->getProcessedUrl();
        if (!preg_match(self::ZIPBALL_URL, $url, $match)) {
            return;
        }

        [, $owner, $repo, $ref] = $match;
        $zip = $this->builder->build($owner, $repo, rawurldecode($ref));

        $this->io->writeError(
            sprintf('  - github-dist-shim: %s/%s@%s from git archive', $owner, $repo, substr($ref, 0, 12)),
            true,
            IOInterface::VERBOSE
        );

        $event->setCustomCacheKey($url);
        $event->setProcessedUrl('file://' . $zip);
    }
}

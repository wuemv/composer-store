<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Integration\Support;

use ComposerStore\Tests\Support\Files;
use ComposerStore\Tests\Support\Process;

/**
 * A local stand-in for Packagist + GitHub, built from tests/Fixtures/packages/<vendor>/<name>/<version>/:
 * one git repository per package with a commit per version, zip dists made with `git archive` (as GitHub
 * makes its zipballs), and a Composer repository listing them with dist and source references.
 */
final class FixtureRepository
{
    private const COMMIT_DATE = '2026-01-01T00:00:00+00:00';

    /**
     * @return array<string, array<string, string>> package name => version => commit hash
     */
    public static function build(string $fixtures, string $root): array
    {
        $packages = [];
        $references = [];
        foreach (glob($fixtures . '/*/*', GLOB_ONLYDIR) ?: [] as $packageDir) {
            $name = basename(dirname($packageDir)) . '/' . basename($packageDir);
            $versions = array_map('basename', glob($packageDir . '/*', GLOB_ONLYDIR) ?: []);
            usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

            $repo = $root . '/git/' . $name;
            Files::makeDir($repo);
            self::git($repo, 'init', '--quiet');
            foreach ($versions as $version) {
                self::replaceWorkTree($repo, $packageDir . '/' . $version);
                self::git($repo, 'add', '--all');
                self::git($repo, 'commit', '--quiet', '--message', 'Release ' . $version);
                $commit = trim(self::git($repo, 'rev-parse', 'HEAD'));

                $zip = sprintf('%s/dists/%s/%s.zip', $root, $name, $version);
                Files::makeDir(dirname($zip));
                $prefix = sprintf('%s-%s/', str_replace('/', '-', $name), substr($commit, 0, 7));
                self::git($repo, 'archive', '--format=zip', '--prefix=' . $prefix, '--output=' . $zip, $commit);

                $packages[$name][$version] = Files::readJson($packageDir . '/' . $version . '/composer.json') + [
                    'version' => $version,
                    'dist' => ['type' => 'zip', 'url' => self::fileUrl($zip), 'reference' => $commit],
                    'source' => ['type' => 'git', 'url' => $repo, 'reference' => $commit],
                ];
                $references[$name][$version] = $commit;
            }
        }
        Files::writeJson($root . '/repository/packages.json', ['packages' => $packages]);

        return $references;
    }

    private static function replaceWorkTree(string $repo, string $source): void
    {
        foreach (scandir($repo) ?: [] as $entry) {
            if (!in_array($entry, ['.', '..', '.git'], true)) {
                Files::remove($repo . '/' . $entry);
            }
        }
        Files::copyTree($source, $repo);
    }

    private static function git(string $repo, string ...$args): string
    {
        $env = getenv() + [
            'GIT_AUTHOR_NAME' => 'Fixture',
            'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
            'GIT_AUTHOR_DATE' => self::COMMIT_DATE,
            'GIT_COMMITTER_NAME' => 'Fixture',
            'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
            'GIT_COMMITTER_DATE' => self::COMMIT_DATE,
        ];
        $options = ['-c', 'commit.gpgsign=false', '-c', 'core.autocrlf=false', '-c', 'init.defaultBranch=main'];
        $result = Process::run(['git', ...$options, ...array_values($args)], $repo, $env);
        if ($result->exitCode !== 0) {
            throw new \RuntimeException($result->describe());
        }

        return $result->stdout;
    }

    public static function fileUrl(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return 'file://' . (str_starts_with($path, '/') ? '' : '/') . $path;
    }
}

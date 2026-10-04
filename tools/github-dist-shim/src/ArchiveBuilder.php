<?php

declare(strict_types=1);

namespace ComposerStore\Tools\GitHubDistShim;

/**
 * Builds the equivalent of a GitHub zipball: `git archive` of one commit, which
 * honours export-ignore / export-subst exactly like GitHub does.
 */
final class ArchiveBuilder
{
    public function __construct(private readonly string $dir)
    {
    }

    public function build(string $owner, string $repo, string $ref): string
    {
        $zip = sprintf('%s/zips/%s/%s/%s.zip', $this->dir, self::safe($owner), self::safe($repo), self::safe($ref));
        if (is_file($zip)) {
            return $zip;
        }

        $gitDir = sprintf('%s/git/%s/%s.git', $this->dir, self::safe($owner), self::safe($repo));
        self::mkdir(dirname($zip));
        self::mkdir(dirname($gitDir));

        $lock = fopen($gitDir . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('github-dist-shim: cannot lock ' . $gitDir);
        }

        try {
            if (is_file($zip)) {
                return $zip;
            }
            if (!is_file($gitDir . '/HEAD')) {
                self::git(['init', '--quiet', '--bare', $gitDir]);
            }

            $remote = sprintf('https://github.com/%s/%s.git', $owner, $repo);
            self::git(['--git-dir=' . $gitDir, 'fetch', '--quiet', '--depth=1', '--no-tags', $remote, $ref]);
            $sha = trim(self::git(['--git-dir=' . $gitDir, 'rev-parse', 'FETCH_HEAD^{commit}']));

            $tmp = $zip . '.' . getmypid() . '.tmp';
            $prefix = sprintf('%s-%s-%s/', $owner, $repo, substr($sha, 0, 7));
            self::git(['--git-dir=' . $gitDir, 'archive', '--format=zip', '--prefix=' . $prefix, '-o', $tmp, $sha]);

            if (!rename($tmp, $zip)) {
                throw new \RuntimeException('github-dist-shim: cannot move archive to ' . $zip);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $zip;
    }

    /**
     * @param list<string> $args
     */
    private static function git(array $args): string
    {
        $env = getenv() + ['GIT_TERMINAL_PROMPT' => '0'];
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('github-dist-shim: cannot run git');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new \RuntimeException(sprintf("github-dist-shim: git %s failed:\n%s", implode(' ', $args), $stderr));
        }

        return $stdout;
    }

    private static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('github-dist-shim: cannot create ' . $dir);
        }
    }

    private static function safe(string $part): string
    {
        return (string) preg_replace('{[^A-Za-z0-9._-]}', '_', $part);
    }
}

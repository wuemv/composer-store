<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Support;

final class Process
{
    /**
     * Runs a command without a shell and waits for it.
     *
     * @param list<string>          $command
     * @param array<string, string> $env     the complete environment of the process
     */
    public static function run(array $command, string $cwd, array $env): ProcessResult
    {
        return self::start($command, $cwd, $env)->wait();
    }

    /**
     * Starts a command without a shell. Output goes through temp files rather than pipes: Composer
     * writes most of its output to stderr, and draining two pipes one after the other can deadlock.
     *
     * @param list<string>          $command
     * @param array<string, string> $env     the complete environment of the process
     */
    public static function start(array $command, string $cwd, array $env): RunningProcess
    {
        $stdout = Files::tempFile('stdout');
        $stderr = Files::tempFile('stderr');
        $descriptors = [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', $stdout, 'w'],
            2 => ['file', $stderr, 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start ' . implode(' ', $command));
        }

        return new RunningProcess($process, $command, $stdout, $stderr);
    }

    /**
     * The current environment, without any COMPOSER* variable.
     *
     * @return array<string, string>
     */
    public static function environmentWithoutComposer(): array
    {
        return array_filter(
            getenv(),
            static fn (string $name): bool => !str_starts_with(strtoupper($name), 'COMPOSER'),
            ARRAY_FILTER_USE_KEY
        );
    }
}

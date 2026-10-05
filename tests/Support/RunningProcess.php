<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Support;

/**
 * A process started by Process::start(). Its output goes to temp files, readable while it runs.
 */
final class RunningProcess
{
    private ?ProcessResult $result = null;

    /**
     * @param resource     $process
     * @param list<string> $command
     */
    public function __construct(
        private $process,
        private readonly array $command,
        private readonly string $stdoutFile,
        private readonly string $stderrFile,
    ) {
    }

    /**
     * What the process wrote to stdout so far.
     */
    public function output(): string
    {
        return (string) @file_get_contents($this->stdoutFile);
    }

    public function wait(): ProcessResult
    {
        if ($this->result === null) {
            $exitCode = proc_close($this->process);
            $stdout = (string) file_get_contents($this->stdoutFile);
            $stderr = (string) file_get_contents($this->stderrFile);
            @unlink($this->stdoutFile);
            @unlink($this->stderrFile);
            $this->result = new ProcessResult($this->command, $exitCode, $stdout, $stderr);
        }

        return $this->result;
    }
}

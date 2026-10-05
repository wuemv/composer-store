<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Support;

final class ProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function output(): string
    {
        return $this->stdout . $this->stderr;
    }

    public function describe(): string
    {
        return sprintf(
            "$ %s\nexit code %d\n--- stdout\n%s\n--- stderr\n%s",
            implode(' ', $this->command),
            $this->exitCode,
            $this->stdout,
            $this->stderr
        );
    }
}

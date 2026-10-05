<?php

declare(strict_types=1);

namespace ComposerStore\Link;

/**
 * Runs system commands such as cp, without a shell, for probes and one-off calls.
 */
final class Command
{
    /**
     * Runs a command without a shell.
     *
     * @param list<string> $command
     *
     * @return array{int, string, string} exit status, output when captured, first line of the errors
     */
    public static function run(array $command, bool $captureOutput = false): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $captureOutput ? ['pipe', 'w'] : ['file', '/dev/null', 'w'],
            // Errors only when output is not captured: reading two pipes one after the other could block.
            2 => $captureOutput ? ['file', '/dev/null', 'w'] : ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            return [-1, '', 'cannot run ' . $command[0]];
        }
        $stream = $pipes[$captureOutput ? 1 : 2];
        $text = (string) stream_get_contents($stream);
        fclose($stream);
        $status = proc_close($process);

        if ($captureOutput) {
            return [$status, $text, ''];
        }
        $error = trim(strtok($text, "\n") ?: '');
        if ($error === '') {
            $error = $status === 127 ? $command[0] . ' not found' : 'exit status ' . $status;
        }

        return [$status, '', $error];
    }
}

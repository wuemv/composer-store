<?php

declare(strict_types=1);

namespace ComposerStore\Monitor;

/**
 * Serves the dashboard: a page with live charts and the measurements it polls, on 127.0.0.1 only.
 * It measures every interval for as long as it serves, and stops with the process: nothing runs in
 * the background. Plain HTTP/1.1 over a stream socket, one request per connection, with nothing
 * beyond PHP's core.
 */
final class DashboardServer
{
    private const PAGE = __DIR__ . '/dashboard.html';
    private const MAX_REQUEST_BYTES = 16384;

    /** @var resource|null */
    private $socket = null;

    private int $port = 0;

    private ?Snapshot $snapshot = null;

    private ?int $startFree = null;

    private readonly float $started;

    private readonly History $history;

    public function __construct(
        private readonly UsageScanner $scanner,
        private readonly string $dir,
        private readonly float $interval,
    ) {
        $this->started = microtime(true);
        $this->history = new History($this->started);
    }

    /**
     * Listens on 127.0.0.1 and takes the first measurement, so the page has data from the start.
     *
     * @param int $port 0 for any free port
     *
     * @return string the dashboard's address
     *
     * @throws \RuntimeException when the port cannot be had
     */
    public function start(int $port): string
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
        if ($socket === false) {
            throw new \RuntimeException(sprintf('Cannot listen on 127.0.0.1:%d: %s', $port, $error));
        }
        $name = (string) stream_socket_get_name($socket, false);
        $this->port = (int) substr($name, (int) strrpos($name, ':') + 1);
        $this->socket = $socket;
        $this->measure();

        return sprintf('http://127.0.0.1:%d/', $this->port);
    }

    /**
     * Measures every interval and answers requests in between, until the process is stopped.
     */
    public function serve(): never
    {
        $socket = $this->socket;
        if ($socket === null) {
            throw new \LogicException('start() first');
        }
        $next = microtime(true) + $this->interval;
        while (true) {
            $wait = max(0.0, $next - microtime(true));
            $read = [$socket];
            $write = $except = null;
            if (@stream_select($read, $write, $except, (int) $wait, (int) (fmod($wait, 1.0) * 1e6)) > 0) {
                $client = @stream_socket_accept($socket, 0);
                if ($client !== false) {
                    $this->answer($client);
                }
            }
            if (microtime(true) >= $next) {
                $this->measure();
                $next = microtime(true) + $this->interval;
            }
        }
    }

    public function stop(): void
    {
        if ($this->socket !== null) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * The response to an HTTP request: the page, the measurements as JSON, or an error.
     */
    public function respond(string $request): string
    {
        $lines = explode("\r\n", $request);
        $requestLine = explode(' ', $lines[0]);
        $method = $requestLine[0];
        $path = strtok($requestLine[1] ?? '', '?');
        $host = null;
        foreach (array_slice($lines, 1) as $line) {
            if (stripos($line, 'host:') === 0) {
                $host = strtolower(trim(substr($line, 5)));
            }
        }
        // Only this machine's names: a page elsewhere whose name now points at 127.0.0.1 (DNS
        // rebinding) is turned away.
        if (!in_array($host, ['127.0.0.1:' . $this->port, 'localhost:' . $this->port], true)) {
            return self::response(403, 'text/plain; charset=utf-8', "Forbidden\n");
        }
        if ($method !== 'GET') {
            return self::response(405, 'text/plain; charset=utf-8', "Method not allowed\n", ['Allow: GET']);
        }

        return match ($path) {
            '/' => $this->page(),
            '/data.json' => self::response(200, 'application/json', $this->data()),
            default => self::response(404, 'text/plain; charset=utf-8', "Not found\n"),
        };
    }

    private function measure(): void
    {
        $this->snapshot = $this->scanner->snapshot($this->dir);
        $this->startFree ??= $this->snapshot->freeBytes;
        $this->history->add(microtime(true), $this->snapshot);
    }

    /**
     * @param resource $client
     */
    private function answer($client): void
    {
        stream_set_timeout($client, 2);
        $request = '';
        while (!str_contains($request, "\r\n\r\n") && strlen($request) < self::MAX_REQUEST_BYTES) {
            $chunk = fread($client, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $request .= $chunk;
        }
        @fwrite($client, $this->respond($request));
        fclose($client);
    }

    private function page(): string
    {
        $nonce = base64_encode(random_bytes(16));
        $html = str_replace('{{nonce}}', $nonce, (string) file_get_contents(self::PAGE));

        return self::response(200, 'text/html; charset=utf-8', $html, [
            "Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}';"
            . " connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
        ]);
    }

    private function data(): string
    {
        return (string) json_encode([
            'snapshot' => $this->snapshot?->toArray(),
            'interval' => $this->interval,
            'elapsed' => round(microtime(true) - $this->started, 1),
            'start-free-bytes' => $this->startFree,
            'history' => $this->history->samples(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<string> $headers
     */
    private static function response(int $status, string $type, string $body, array $headers = []): string
    {
        $reasons = [200 => 'OK', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed'];
        $head = [
            sprintf('HTTP/1.1 %d %s', $status, $reasons[$status] ?? ''),
            'Content-Type: ' . $type,
            'Content-Length: ' . strlen($body),
            'Cache-Control: no-store',
            'X-Content-Type-Options: nosniff',
            'Connection: close',
            ...$headers,
        ];

        return implode("\r\n", $head) . "\r\n\r\n" . $body;
    }
}

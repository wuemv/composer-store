<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Monitor;

use ComposerStore\Monitor\DashboardServer;
use ComposerStore\Monitor\UsageScanner;
use ComposerStore\Store\Store;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

/**
 * Asks the server for responses directly, without a browser, once start() has bound its port.
 */
final class DashboardServerTest extends TestCase
{
    private string $dir;

    private DashboardServer $server;

    private int $port;

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('dashboard-server-test');
        Files::writeJson($this->dir . '/sites/shop/composer.json', []);
        $scanner = new UsageScanner(new Store($this->dir . '/store'), null, 'Linux');
        $this->server = new DashboardServer($scanner, $this->dir . '/sites', 2.0);
        $url = $this->server->start(0);
        $this->assertMatchesRegularExpression('{^http://127\.0\.0\.1:\d+/$}', $url);
        $this->port = (int) parse_url($url, PHP_URL_PORT);
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        Files::remove($this->dir);
    }

    public function testServesThePageWithAFreshNonceForItsScriptAndStyles(): void
    {
        [$status, $headers, $body] = $this->get('/');

        $this->assertSame(200, $status);
        $this->assertSame('text/html; charset=utf-8', $headers['content-type']);
        $this->assertSame('no-store', $headers['cache-control']);
        $this->assertStringContainsString("default-src 'none'", $headers['content-security-policy']);
        $nonce = self::nonce($headers['content-security-policy']);
        $this->assertNotSame('', $nonce);
        $this->assertStringContainsString('<script nonce="' . $nonce . '">', $body);
        $this->assertStringContainsString('<style nonce="' . $nonce . '">', $body);
        $this->assertStringNotContainsString('{{nonce}}', $body);
        $this->assertNotSame($nonce, self::nonce($this->get('/')[1]['content-security-policy']), 'one per response');
    }

    public function testServesTheMeasurementsAsJson(): void
    {
        [$status, $headers, $body] = $this->get('/data.json?t=1');

        $this->assertSame(200, $status);
        $this->assertSame('application/json', $headers['content-type']);
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertEquals(2, $data['interval'], 'json_encode() writes 2.0 as 2');
        $this->assertIsArray($data['snapshot']);
        $this->assertSame($this->dir . '/sites', $data['snapshot']['dir']);
        $this->assertIsArray($data['snapshot']['projects']);
        $this->assertCount(1, $data['snapshot']['projects']);
        $this->assertIsArray($data['history']);
        $this->assertCount(1, $data['history'], 'start() measured once');
        $this->assertSame($data['snapshot']['free-bytes'], $data['start-free-bytes']);
    }

    public function testAnswersOnlyToThisMachinesNames(): void
    {
        $this->assertSame(200, $this->get('/data.json', 'localhost:' . $this->port)[0]);
        $this->assertSame(403, $this->get('/data.json', 'attacker.example:' . $this->port)[0], 'DNS rebinding');
        $this->assertSame(403, $this->get('/data.json', '127.0.0.1:' . ($this->port + 1))[0]);
        $this->assertSame(403, $this->get('/data.json', null)[0]);
    }

    public function testRejectsOtherMethodsAndPaths(): void
    {
        $response = $this->server->respond("POST /data.json HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n\r\n");
        $this->assertStringStartsWith('HTTP/1.1 405 ', $response);
        $this->assertStringContainsString("\r\nAllow: GET\r\n", $response);

        $this->assertSame(404, $this->get('/../composer.json')[0]);
        $this->assertSame(404, $this->get('/dashboard.html')[0]);
        $this->assertStringStartsWith('HTTP/1.1 403 ', $this->server->respond(''), 'no request line, no Host');
    }

    private static function nonce(string $policy): string
    {
        return preg_match("{script-src 'nonce-([^']+)'}", $policy, $match) === 1 ? $match[1] : '';
    }

    /**
     * @return array{int, array<string, string>, string} status, headers by lowercase name, body
     */
    private function get(string $path, ?string $host = ''): array
    {
        $host = $host === '' ? '127.0.0.1:' . $this->port : $host;
        $request = "GET {$path} HTTP/1.1\r\n" . ($host === null ? '' : "Host: {$host}\r\n") . "\r\n";
        [$head, $body] = explode("\r\n\r\n", $this->server->respond($request), 2);
        $lines = explode("\r\n", $head);
        $status = (int) explode(' ', $lines[0])[1];
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }

        return [$status, $headers, $body];
    }
}

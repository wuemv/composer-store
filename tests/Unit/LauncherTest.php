<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit;

use ComposerStore\Launcher;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

/**
 * Stand-in PHPs and Composers on a PATH of their own; whether a PHP has FFI is decided by the test.
 */
final class LauncherTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $env = [];

    protected function setUp(): void
    {
        $this->dir = Files::tempDir('launcher-test');
        foreach (['COMPOSER_STORE_PHP', 'COMPOSER_STORE_COMPOSER', 'LAUNCHER_TEST_ARGS'] as $name) {
            $this->env[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->env as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        Files::remove($this->dir);
    }

    public function testRunsComposersStoreCommandWithTheRestOfTheArguments(): void
    {
        $composer = $this->file(
            'bin/composer',
            "<?php\nfile_put_contents(getenv('LAUNCHER_TEST_ARGS'), json_encode(array_slice(\$argv, 1)));\nexit(3);\n"
        );
        putenv('COMPOSER_STORE_COMPOSER=' . $composer);
        putenv('LAUNCHER_TEST_ARGS=' . $this->dir . '/args.json');

        $status = (new Launcher('Linux', ''))->run(['dashboard', '~/Sites', '--port=8000']);

        $this->assertSame(3, $status, "Composer's exit status");
        $this->assertSame(['store:dashboard', '~/Sites', '--port=8000'], Files::readJson($this->dir . '/args.json'));
    }

    public function testPrintsItsCommandsForHelpAndRejectsOthers(): void
    {
        [$stdout, $stderr] = [$this->stream(), $this->stream()];
        $launcher = new Launcher('Linux', '');

        $this->assertSame(0, $launcher->run([], $stdout, $stderr));
        $this->assertSame(0, $launcher->run(['--help'], $stdout, $stderr));
        $this->assertSame(1, $launcher->run(['install'], $stdout, $stderr));

        $this->assertStringContainsString('composer-store dashboard', self::read($stdout));
        $this->assertStringContainsString('Unknown command: install', self::read($stderr));
    }

    public function testFailsWhenThereIsNoComposer(): void
    {
        $stderr = $this->stream();

        $this->assertSame(1, (new Launcher('Linux', $this->dir))->run(['status'], $this->stream(), $stderr));
        $this->assertStringContainsString('Composer is not on the PATH', self::read($stderr));
    }

    public function testRunsAPhpComposerWithTheChosenPhpAndOthersAsTheyAre(): void
    {
        $this->file('one/composer', "#!/usr/bin/env php\n<?php\n");
        $this->file('two/composer', "#!/bin/sh\nexec real-composer \"\$@\"\n");

        $one = (new Launcher('Linux', $this->dir . '/one'))->composer();
        $this->assertSame([PHP_BINARY, $this->dir . '/one/composer'], $one);
        $this->assertSame([$this->dir . '/two/composer'], (new Launcher('Linux', $this->dir . '/two'))->composer());
        $this->assertNull((new Launcher('Linux', $this->dir . '/none'))->composer());
    }

    public function testKeepsThisPhpOutsideMacOsOrWhenItHasFfi(): void
    {
        $path = $this->dir . '/herd';
        $this->file('herd/php', '');
        $noFfi = static fn (string $php): bool => false;
        $ffi = static fn (string $php): bool => true;

        $this->assertSame(PHP_BINARY, (new Launcher('Linux', $path, $noFfi))->php());
        $this->assertSame(PHP_BINARY, (new Launcher('Darwin', $path, $ffi))->php(), 'this one has FFI');
    }

    public function testOnMacOsFindsAPhpOfThisVersionWithFfiOnThePath(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('is_executable() only takes .exe, .bat and the like on Windows');
        }
        $version = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $this->file('yerd/php', '');
        $this->file('herd/php' . ($version + 1), '');
        $herd = $this->file('herd/php' . $version, '');
        $hasFfi = static fn (string $php): bool => str_contains($php, '/herd/');
        $path = $this->dir . '/yerd' . PATH_SEPARATOR . $this->dir . '/herd';

        $this->assertSame($herd, (new Launcher('Darwin', $path, $hasFfi))->php(), 'not yerd, not another version');
        $this->assertSame(PHP_BINARY, (new Launcher('Darwin', $this->dir . '/yerd', $hasFfi))->php(), 'none has FFI');
    }

    public function testComposerStorePhpWins(): void
    {
        putenv('COMPOSER_STORE_PHP=/opt/php/bin/php');

        $launcher = new Launcher('Darwin', '', static fn (string $php): bool => true);
        $this->assertSame('/opt/php/bin/php', $launcher->php());
    }

    private function file(string $relative, string $content): string
    {
        $file = $this->dir . '/' . $relative;
        Files::makeDir(dirname($file));
        file_put_contents($file, $content);
        chmod($file, 0755);

        return $file;
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertNotFalse($stream);

        return $stream;
    }

    /**
     * @param resource $stream
     */
    private static function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}

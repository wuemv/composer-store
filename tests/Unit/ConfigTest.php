<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit;

use Composer\Composer;
use Composer\Config as ComposerConfig;
use Composer\Package\RootPackage;
use ComposerStore\Config;
use ComposerStore\Mode;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string|false $storeDirEnv;

    protected function setUp(): void
    {
        $this->storeDirEnv = getenv('COMPOSER_STORE_DIR');
        $this->setStoreDirEnv(false);
    }

    protected function tearDown(): void
    {
        $this->setStoreDirEnv($this->storeDirEnv);
    }

    public function testDefaultsToAutoMode(): void
    {
        $config = Config::fromSettings([null, null], '/store');

        $this->assertSame(Mode::Auto, $config->mode);
        $this->assertSame('/store', $config->storeDir);
        $this->assertSame([], $config->warnings);
    }

    #[DataProvider('modes')]
    public function testReadsEveryMode(string $value, Mode $expected): void
    {
        $this->assertSame($expected, Config::fromSettings([['mode' => $value]], '/store')->mode);
    }

    /**
     * @return iterable<string, array{string, Mode}>
     */
    public static function modes(): iterable
    {
        foreach (Mode::cases() as $mode) {
            yield $mode->value => [$mode->value, $mode];
        }
    }

    public function testProjectSettingsOverrideGlobalOnes(): void
    {
        $global = ['mode' => 'copy'];

        $this->assertSame(Mode::Hardlink, Config::fromSettings([$global, ['mode' => 'hardlink']], '/s')->mode);
        $this->assertSame(Mode::Copy, Config::fromSettings([$global, []], '/s')->mode);
        $this->assertSame(Mode::Copy, Config::fromSettings([$global, null], '/s')->mode);
    }

    public function testUnknownModesWarnAndFallBackToAuto(): void
    {
        $config = Config::fromSettings([['mode' => 'fast']], '/store');

        $this->assertSame(Mode::Auto, $config->mode);
        $this->assertCount(1, $config->warnings);
        $this->assertStringContainsString('unknown mode "fast"', $config->warnings[0]);
    }

    public function testSettingsThatAreNotAnObjectAreIgnoredWithAWarning(): void
    {
        $config = Config::fromSettings(['copy', ['mode' => 'hardlink']], '/store');

        $this->assertSame(Mode::Hardlink, $config->mode);
        $this->assertSame(['extra.composer-store must be an object, ignoring it'], $config->warnings);
    }

    public function testExcludeListsFromEveryLayerApply(): void
    {
        $config = Config::fromSettings([
            ['exclude' => ['Acme/Global', 'acme/both']],
            ['exclude' => ['acme/project', 'acme/both']],
        ], '/store');

        $this->assertSame(['acme/global', 'acme/both', 'acme/project'], $config->exclude);
        $this->assertSame([], $config->warnings);
    }

    public function testInvalidExcludeEntriesAreIgnoredWithAWarning(): void
    {
        $config = Config::fromSettings([['exclude' => ['acme/ok', 42, '']], ['exclude' => 'acme/not-a-list']], '/s');

        $this->assertSame(['acme/ok'], $config->exclude);
        $this->assertCount(3, $config->warnings);
    }

    public function testReadOnlyIsOffByDefaultAndTheProjectWins(): void
    {
        $this->assertFalse(Config::fromSettings([null], '/s')->readOnly);
        $this->assertTrue(Config::fromSettings([['read-only' => true]], '/s')->readOnly);
        $this->assertFalse(Config::fromSettings([['read-only' => true], ['read-only' => false]], '/s')->readOnly);
    }

    public function testReadOnlyAcceptsTheStringsComposerConfigWrites(): void
    {
        $this->assertTrue(Config::fromSettings([['read-only' => 'true']], '/s')->readOnly);
        $this->assertFalse(Config::fromSettings([['read-only' => '0']], '/s')->readOnly);

        $invalid = Config::fromSettings([['read-only' => 'sometimes']], '/s');
        $this->assertFalse($invalid->readOnly);
        $this->assertSame(['read-only must be true or false, not "sometimes"; using false'], $invalid->warnings);
    }

    public function testTheStoreLivesInTheComposerHomeByDefault(): void
    {
        $this->assertSame('/home/me/.composer/store', Config::storeDir('/home/me/.composer/'));
    }

    public function testTheStoreDirectoryCanBeSetInTheEnvironment(): void
    {
        $this->setStoreDirEnv('/var/cache/composer-store');
        $this->assertSame('/var/cache/composer-store', Config::storeDir('/home/me/.composer'));

        $this->setStoreDirEnv('relative/store');
        $this->assertSame(getcwd() . '/relative/store', Config::storeDir('/home/me/.composer'));
    }

    public function testFromComposerReadsTheGlobalAndTheProjectSettings(): void
    {
        $home = Files::tempDir('config-test');
        Files::writeJson($home . '/composer.json', ['extra' => ['composer-store' => ['mode' => 'copy']]]);
        $composerConfig = new ComposerConfig(false);
        $composerConfig->merge(['config' => ['home' => $home]]);
        $package = new RootPackage('test/app', '1.0.0.0', '1.0.0');
        $composer = new Composer();
        $composer->setConfig($composerConfig);
        $composer->setPackage($package);

        try {
            $this->assertSame(Mode::Copy, Config::fromComposer($composer)->mode);
            $this->assertSame($home . '/store', Config::fromComposer($composer)->storeDir);

            $package->setExtra(['composer-store' => ['mode' => 'hardlink']]);
            $this->assertSame(Mode::Hardlink, Config::fromComposer($composer)->mode);
        } finally {
            Files::remove($home);
        }
    }

    private function setStoreDirEnv(string|false $value): void
    {
        if ($value === false) {
            unset($_SERVER['COMPOSER_STORE_DIR']);
            putenv('COMPOSER_STORE_DIR');
        } else {
            $_SERVER['COMPOSER_STORE_DIR'] = $value;
            putenv('COMPOSER_STORE_DIR=' . $value);
        }
    }
}

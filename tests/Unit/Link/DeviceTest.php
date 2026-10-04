<?php

declare(strict_types=1);

namespace ComposerStore\Tests\Unit\Link;

use ComposerStore\Link\Device;
use ComposerStore\Tests\Support\Files;
use PHPUnit\Framework\TestCase;

final class DeviceTest extends TestCase
{
    public function testPathsThatDoNotExistYetAreOnTheDeviceOfTheirClosestParent(): void
    {
        $dir = Files::tempDir('device-test');
        try {
            $stat = stat($dir);
            $this->assertIsArray($stat);

            $this->assertSame($stat['dev'], Device::of($dir));
            $this->assertSame($stat['dev'], Device::of($dir . '/not/created/yet'));
            $this->assertTrue(Device::same($dir, $dir . '/vendor'));
        } finally {
            Files::remove($dir);
        }
    }
}

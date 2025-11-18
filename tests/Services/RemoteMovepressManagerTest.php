<?php

declare(strict_types=1);

namespace Movepress\Tests\Services;

use Movepress\Services\RemoteMovepressManager;
use Movepress\Services\RemoteTransferService;
use Movepress\Services\SshService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;

class RemoteMovepressManagerTest extends TestCase
{
    public function testStageUploadsExecutableWithinWordpressPath(): void
    {
        $_SERVER['argv'][0] = __FILE__;

        $transfer = $this->createMock(RemoteTransferService::class);
        $ssh = $this->createMock(SshService::class);

        $transfer
            ->expects($this->once())
            ->method('executeRemoteCommand')
            ->with($ssh, $this->stringContains("mkdir -p '/var/www/html/.movepress'"))
            ->willReturn(true);

        $transfer
            ->expects($this->once())
            ->method('uploadFile')
            ->with($ssh, __FILE__, '/var/www/html/.movepress/movepress.phar')
            ->willReturn(true);

        $manager = new RemoteMovepressManager($transfer, new BufferedOutput());
        $remotePath = $manager->stage($ssh, '/var/www/html');

        $this->assertSame('/var/www/html/.movepress/movepress.phar', $remotePath);
    }

    public function testStageThrowsWhenExecutableMissing(): void
    {
        $_SERVER['argv'][0] = '/definitely/missing/movepress';

        $manager = new RemoteMovepressManager($this->createMock(RemoteTransferService::class), new BufferedOutput());

        $this->expectException(RuntimeException::class);
        $manager->stage($this->createMock(SshService::class), '/var/www/html');
    }
}

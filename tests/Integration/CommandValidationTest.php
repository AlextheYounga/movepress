<?php

declare(strict_types=1);

namespace Movepress\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class CommandValidationTest extends TestCase
{
    public function testRsyncAcceptsOurFlags(): void
    {
        if (!$this->isCommandAvailable('rsync')) {
            $this->markTestSkipped('rsync not available');
        }

        $progressFlag = $this->rsyncSupportsProgress2() ? '--info=progress2' : '--progress';
        $command = sprintf(
            'rsync --dry-run -avz --stats --progress %s --exclude=.git /tmp/ /tmp/test_rsync_target/ 2>&1',
            $progressFlag,
        );
        $process = Process::fromShellCommandline($command);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        // Should not contain "unknown option" or "invalid option"
        $this->assertStringNotContainsString('unknown option', strtolower($output));
        $this->assertStringNotContainsString('invalid option', strtolower($output));
    }

    public function testRsyncAcceptsDeleteFlag(): void
    {
        if (!$this->isCommandAvailable('rsync')) {
            $this->markTestSkipped('rsync not available');
        }

        $process = Process::fromShellCommandline('rsync --dry-run -avz --delete /tmp/ /tmp/test_rsync_target/ 2>&1');
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringNotContainsString('unknown option', strtolower($output));
        $this->assertStringNotContainsString('invalid option', strtolower($output));
    }

    public function testMysqldumpAcceptsOurFlags(): void
    {
        if (!$this->isCommandAvailable('mysqldump')) {
            $this->markTestSkipped('mysqldump not available');
        }

        // Test with --help to verify our flags exist
        $process = Process::fromShellCommandline('mysqldump --help 2>&1');
        $process->run();

        $output = $process->getOutput();
        $this->assertStringContainsString('--single-transaction', $output);
        $this->assertStringContainsString('--quick', $output);
        $this->assertStringContainsString('--lock-tables', $output);
    }

    public function testMysqlAcceptsOurFlags(): void
    {
        if (!$this->isCommandAvailable('mysql')) {
            $this->markTestSkipped('mysql not available');
        }

        // Test with --help to verify our flags exist
        $process = Process::fromShellCommandline('mysql --help 2>&1');
        $process->run();

        $output = $process->getOutput();
        $this->assertStringContainsString('--user', $output);
        $this->assertStringContainsString('--password', $output);
        $this->assertStringContainsString('--host', $output);
    }

    public function testSshAcceptsOurFlags(): void
    {
        if (!$this->isCommandAvailable('ssh')) {
            $this->markTestSkipped('ssh not available');
        }

        // ssh with invalid host should fail but still recognize flags
        $process = Process::fromShellCommandline('ssh -o StrictHostKeyChecking=no -p 22 invalidhost 2>&1');
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        // Should not complain about unknown options
        $this->assertStringNotContainsString('unknown option', strtolower($output));
        $this->assertStringNotContainsString('Bad configuration option', $output);
    }

    public function testScpAcceptsOurFlags(): void
    {
        if (!$this->isCommandAvailable('scp')) {
            $this->markTestSkipped('scp not available');
        }

        // scp with invalid source should fail but still recognize flags
        $process = Process::fromShellCommandline(
            'scp -o StrictHostKeyChecking=no -P 22 /nonexistent invalidhost: 2>&1',
        );
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        // Should not complain about unknown options
        $this->assertStringNotContainsString('unknown option', strtolower($output));
        $this->assertStringNotContainsString('Bad configuration option', $output);
    }

    private function isCommandAvailable(string $command): bool
    {
        $process = Process::fromShellCommandline("which {$command}");
        $process->run();
        return $process->isSuccessful();
    }

    private ?bool $progress2Supported = null;

    private function rsyncSupportsProgress2(): bool
    {
        if ($this->progress2Supported !== null) {
            return $this->progress2Supported;
        }

        $process = Process::fromShellCommandline('rsync --version');
        $process->run();

        if (!$process->isSuccessful()) {
            return $this->progress2Supported = false;
        }

        $output = $process->getOutput();
        if (preg_match('/rsync\s+version\s+(\d+)\.(\d+)/i', $output, $matches)) {
            $major = (int) $matches[1];
            $minor = (int) $matches[2];
            if ($major > 3 || ($major === 3 && $minor >= 1)) {
                return $this->progress2Supported = true;
            }
        }

        return $this->progress2Supported = false;
    }
}

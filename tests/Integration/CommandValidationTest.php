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

        // Test a dry-run with our typical flags
        $process = Process::fromShellCommandline(
            'rsync --dry-run -avz --delete --info=progress2 --exclude=.git /tmp/ /tmp/test_rsync_target/ 2>&1'
        );
        $process->run();
        
        $output = $process->getOutput() . $process->getErrorOutput();
        
        // Should not contain "unknown option" or "invalid option"
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

    public function testWpCliAcceptsSearchReplaceCommand(): void
    {
        // Check for bundled wp-cli first, then system wp
        $bundledWp = dirname(__DIR__, 2) . '/vendor/bin/wp';
        $wpCommand = file_exists($bundledWp) ? $bundledWp : 'wp';
        
        if (!file_exists($bundledWp) && !$this->isCommandAvailable('wp')) {
            $this->markTestSkipped('wp-cli not available');
        }

        // Check that search-replace command exists
        $process = Process::fromShellCommandline("{$wpCommand} help search-replace 2>&1");
        $process->run();
        
        $this->assertTrue(
            $process->isSuccessful(),
            'wp-cli does not support search-replace command'
        );

        // Verify our flags are supported
        $output = $process->getOutput();
        $this->assertStringContainsString('--skip-columns', $output);
        $this->assertStringContainsString('--path', $output);
        $this->assertStringContainsString('--quiet', $output);
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
        $process = Process::fromShellCommandline('scp -o StrictHostKeyChecking=no -P 22 /nonexistent invalidhost: 2>&1');
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
}

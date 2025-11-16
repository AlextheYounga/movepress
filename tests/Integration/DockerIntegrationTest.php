<?php

declare(strict_types=1);

namespace Movepress\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Docker-based integration tests for Movepress
 *
 * These tests spin up a complete WordPress environment with Docker
 * and test the full push/pull/backup workflow end-to-end.
 *
 * @group docker
 * @group integration
 */
class DockerIntegrationTest extends TestCase
{
    private static string $projectRoot;
    private static string $dockerDir;
    private static string $movepressBin;
    private static bool $environmentReady = false;

    public static function setUpBeforeClass(): void
    {
        self::$projectRoot = dirname(__DIR__, 2);
        self::$dockerDir = self::$projectRoot . '/tests/docker';
        self::$movepressBin = self::$projectRoot . '/build/movepress.phar';

        // Check if movepress.phar exists
        if (!file_exists(self::$movepressBin)) {
            self::markTestSkipped('movepress.phar not found. Run: composer install && ./vendor/bin/box compile');
        }

        // Check if Docker is available
        $process = Process::fromShellCommandline('docker --version');
        $process->run();
        if (!$process->isSuccessful()) {
            self::markTestSkipped('Docker is not available');
        }

        echo "\n🐳 Setting up Docker test environment...\n";

        // Generate SSH keys
        self::executeCommand('bash setup-ssh.sh', self::$dockerDir);

        // Stop and remove any existing containers
        self::executeCommand('docker compose down -v 2>/dev/null || true', self::$dockerDir);

        // Build and start containers
        self::executeCommand('docker compose build --quiet', self::$dockerDir);
        self::executeCommand('docker compose up -d', self::$dockerDir);

        // Wait for containers to be ready
        echo "⏳ Waiting for WordPress installations to complete...\n";
        self::waitForEnvironmentReady();

        self::$environmentReady = true;
        echo "✅ Docker environment ready!\n\n";
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$environmentReady) {
            echo "\n🧹 Cleaning up Docker environment...\n";
            self::executeCommand('docker compose down -v', self::$dockerDir);
            echo "✅ Cleanup complete\n";
        }
    }

    public function testConfigurationValidation(): void
    {
        $process = $this->runMovepress('validate');

        $this->assertTrue($process->isSuccessful(), 'Configuration validation should succeed');
        $this->assertStringContainsString('valid', $process->getOutput());
    }

    public function testSystemStatus(): void
    {
        $process = $this->runMovepress('status');

        $this->assertTrue($process->isSuccessful(), 'Status command should succeed');
    }

    public function testSshConnectivity(): void
    {
        $sshCommand = sprintf(
            'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i %s/ssh/id_rsa -p 2222 root@localhost "echo \'SSH OK\'" 2>/dev/null',
            self::$dockerDir,
        );

        $process = Process::fromShellCommandline($sshCommand);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'SSH connection should work');
        $this->assertStringContainsString('SSH OK', $process->getOutput());
    }

    public function testDatabasePush(): void
    {
        $process = $this->runMovepress('push local remote --db --verbose');

        $this->assertTrue($process->isSuccessful(), 'Database push should succeed');

        // Verify database was transferred by checking post count
        $postCount = $this->getRemotePostCount();
        $this->assertGreaterThan(0, $postCount, 'Remote database should contain posts after push');
    }

    public function testFilePush(): void
    {
        $process = $this->runMovepress('push local remote --files --verbose');

        $this->assertTrue($process->isSuccessful(), 'File push should succeed');

        // Verify files were transferred
        $fileExists = $this->remoteFileExists('/var/www/html/wp-content/uploads/2024/11/test-local.txt');
        $this->assertTrue($fileExists, 'Test file should exist on remote after push');
    }

    public function testDatabasePull(): void
    {
        // First ensure remote has data
        $this->runMovepress('push local remote --db');

        // Now pull it back
        $process = $this->runMovepress('pull local remote --db --verbose');

        $this->assertTrue($process->isSuccessful(), 'Database pull should succeed');

        // Verify local database has data
        $postCount = $this->getLocalPostCount();
        $this->assertGreaterThan(0, $postCount, 'Local database should contain posts after pull');
    }

    public function testBackup(): void
    {
        $process = $this->runMovepress('backup local');

        $this->assertTrue($process->isSuccessful(), 'Backup should succeed');
        $this->assertStringContainsString('backup', strtolower($process->getOutput()));
    }

    public function testDryRun(): void
    {
        $process = $this->runMovepress('push local remote --db --dry-run');

        $this->assertTrue($process->isSuccessful(), 'Dry run should succeed');
        $this->assertStringContainsString('dry', strtolower($process->getOutput()));
    }

    private function runMovepress(string $command): Process
    {
        $fullCommand = sprintf('%s %s --config=%s/movefile.yml', self::$movepressBin, $command, self::$dockerDir);

        $process = Process::fromShellCommandline($fullCommand);
        $process->setTimeout(300);
        $process->run();

        return $process;
    }

    private function getRemotePostCount(): int
    {
        $command =
            'docker exec movepress-mysql-remote mysql -uwordpress -pwordpress wordpress_remote -se "SELECT COUNT(*) FROM wp_posts WHERE post_type=\'post\' AND post_status=\'publish\'"';

        $process = Process::fromShellCommandline($command);
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function getLocalPostCount(): int
    {
        $command =
            'docker exec movepress-mysql-local mysql -uwordpress -pwordpress wordpress_local -se "SELECT COUNT(*) FROM wp_posts WHERE post_type=\'post\' AND post_status=\'publish\'"';

        $process = Process::fromShellCommandline($command);
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function remoteFileExists(string $path): bool
    {
        $command = sprintf(
            'ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i %s/ssh/id_rsa -p 2222 root@localhost "[ -f %s ]" 2>/dev/null',
            self::$dockerDir,
            escapeshellarg($path),
        );

        $process = Process::fromShellCommandline($command);
        $process->run();

        return $process->isSuccessful();
    }

    private static function waitForEnvironmentReady(): void
    {
        $maxAttempts = 60;
        $localReady = false;
        $remoteReady = false;

        for ($i = 0; $i < $maxAttempts; $i++) {
            if (!$localReady) {
                $process = Process::fromShellCommandline('docker logs movepress-local 2>&1');
                $process->run();
                if (str_contains($process->getOutput(), 'Local environment ready')) {
                    $localReady = true;
                    echo "  ✓ Local environment ready\n";
                }
            }

            if (!$remoteReady) {
                $process = Process::fromShellCommandline('docker logs movepress-remote 2>&1');
                $process->run();
                if (str_contains($process->getOutput(), 'Remote environment ready')) {
                    $remoteReady = true;
                    echo "  ✓ Remote environment ready\n";
                }
            }

            if ($localReady && $remoteReady) {
                return;
            }

            sleep(2);
        }

        throw new \RuntimeException('Docker environments failed to start within timeout');
    }

    private static function executeCommand(string $command, ?string $cwd = null): void
    {
        $process = Process::fromShellCommandline($command);
        $process->setTimeout(300);

        if ($cwd) {
            $process->setWorkingDirectory($cwd);
        }

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                sprintf(
                    "Command failed: %s\nOutput: %s\nError: %s",
                    $command,
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ),
            );
        }
    }
}

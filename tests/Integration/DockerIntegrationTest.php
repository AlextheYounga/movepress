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

        // Try to reuse existing containers to avoid heavy rebuilds
        // If services exist, restart them; otherwise build and start fresh
        $ps = Process::fromShellCommandline('docker compose ps -q');
        $ps->setWorkingDirectory(self::$dockerDir);
        $ps->run();

        if (trim($ps->getOutput()) !== '') {
            // Containers exist: recreate to ensure clean, idempotent state without rebuilding images
            self::executeCommand('docker compose up -d --force-recreate', self::$dockerDir);
        } else {
            // First run or containers missing: build and start
            self::executeCommand('docker compose build --quiet', self::$dockerDir);
            self::executeCommand('docker compose up -d', self::$dockerDir);
        }

        // Wait for containers to be ready
        echo "⏳ Waiting for WordPress installations to complete...\n";
        self::waitForEnvironmentReady();

        self::$environmentReady = true;
        echo "✅ Docker environment ready!\n\n";
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$environmentReady) {
            // Leave containers running between test runs to reduce CPU churn.
            // If you need to stop them manually:
            //   cd tests/docker && docker compose stop
            echo "\nℹ️ Leaving Docker environment running to speed up subsequent runs.\n";
        }
    }

    public function testConfigurationValidation(): void
    {
        $process = $this->runMovepress('validate');

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf(
                "Configuration validation failed.\nOutput: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );
        $this->assertStringContainsString('valid', $process->getOutput());
    }

    public function testSystemStatus(): void
    {
        $process = $this->runMovepress('status');

        $this->assertTrue($process->isSuccessful(), 'Status command should succeed');
    }

    public function testSshConnectivity(): void
    {
        // Test SSH from inside local container to remote container
        $sshCommand =
            'docker exec movepress-local ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa root@wordpress-remote "echo \'SSH OK\'"';

        $process = Process::fromShellCommandline($sshCommand);
        $process->run();

        $this->assertTrue($process->isSuccessful(), 'SSH connection should work');
        $this->assertStringContainsString('SSH OK', $process->getOutput());
    }

    public function testGitSetup(): void
    {
        // Initialize git repo in local container and configure identity
        $initCommand = "docker exec movepress-local bash -lc 'git config --global --add safe.directory /var/www/html && git -C /var/www/html init && git -C /var/www/html config user.name \"Test User\" && git -C /var/www/html config user.email \"test@example.com\"'";
        $process = Process::fromShellCommandline($initCommand);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Git repo initialization should succeed');

        // Create initial commit
        $commitCommand = "docker exec movepress-local bash -lc 'git -C /var/www/html add -A && git -C /var/www/html commit -m \"Initial commit\"'";
        $process = Process::fromShellCommandline($commitCommand);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Initial commit should succeed');

        // Run git setup command
        $process = $this->runMovepress('git:setup remote --no-interaction');

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf(
                "Git setup should succeed.\nOutput: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        // Verify git remote was added
        $remoteCheckCommand = "docker exec movepress-local bash -lc 'git -C /var/www/html remote get-url remote'";
        $process = Process::fromShellCommandline($remoteCheckCommand);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Git remote should be configured');
        $this->assertStringContainsString('wordpress-remote', $process->getOutput());

        // Create a test file and push it
        // Ensure a change exists every run (append a unique timestamp)
        $createFileCommand = "docker exec movepress-local bash -lc 'echo \"Test git deployment $(date +%s%N)\" >> /var/www/html/git-test.txt'";
        $process = Process::fromShellCommandline($createFileCommand);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Test file creation should succeed');
        // Push using GIT_SSH_COMMAND to bypass host key prompts and use our key
        $pushCommand = "docker exec movepress-local bash -lc 'git -C /var/www/html add git-test.txt && (git -C /var/www/html commit -m \"Add test file\" || true) && GIT_SSH_COMMAND=\"ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa\" git -C /var/www/html push remote master'";
        $process = Process::fromShellCommandline($pushCommand);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Git push should succeed');

        // Verify file was deployed to remote
        $fileExists = $this->remoteFileExists('/var/www/html/git-test.txt');
        $this->assertTrue($fileExists, 'Test file should exist on remote after git push');
    }

    public function testDatabasePush(): void
    {
        // First verify local database has posts
        $localPostCount = $this->getLocalPostCount();
        $this->assertGreaterThan(0, $localPostCount, 'Local database should have test posts before pushing');

        $process = $this->runMovepress('push local remote --db --verbose --no-interaction');

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf(
                "Database push should succeed.\nOutput: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        // Verify database was transferred by checking post count
        $postCount = $this->getRemotePostCount();
        $this->assertGreaterThan(
            0,
            $postCount,
            sprintf(
                "Remote database should contain posts after push (local had %d).\nCommand output: %s\nError: %s",
                $localPostCount,
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );
    }

    public function testFilePush(): void
    {
        $process = $this->runMovepress('push local remote --untracked-files --verbose --no-interaction');

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf(
                "File push should succeed.\nOutput: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        // Verify files were transferred
        $fileExists = $this->remoteFileExists('/var/www/html/wp-content/uploads/2024/11/test-local.jpg');
        $this->assertTrue(
            $fileExists,
            sprintf(
                "Test file should exist on remote after push.\nCommand output: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );
    }

    public function testDatabasePull(): void
    {
        // First ensure remote has data
        $this->runMovepress('push local remote --db --no-interaction');

        // Now pull it back
        $process = $this->runMovepress('pull local remote --db --verbose --no-interaction');

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf(
                "Database pull should succeed.\nOutput: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        // Verify local database has data
        $postCount = $this->getLocalPostCount();
        $this->assertGreaterThan(
            0,
            $postCount,
            sprintf(
                "Local database should contain posts after pull.\nCommand output: %s\nError: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );
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
        // Execute movepress inside the local container
        // Use -i flag for interactive mode to allow stdin input
        // Run with working directory set to WordPress root so git-related commands see the repo
        $fullCommand = sprintf('docker exec -w /var/www/html -i movepress-local movepress %s', $command);

        $process = Process::fromShellCommandline($fullCommand);
        $process->setTimeout(300);

        // For destructive operations (push/pull with --db), provide "yes" as input
        if (str_contains($command, 'push') || str_contains($command, 'pull')) {
            $process->setInput("yes\n");
        }

        $process->run();

        return $process;
    }

    private function getRemotePostCount(): int
    {
        $command =
            'docker exec movepress-mysql-remote mariadb -uwordpress -pwordpress wordpress_remote -se "SELECT COUNT(*) FROM wp_posts WHERE post_type=\'post\' AND post_status=\'publish\'"';

        $process = Process::fromShellCommandline($command);
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function getLocalPostCount(): int
    {
        $command =
            'docker exec movepress-mysql-local mariadb -uwordpress -pwordpress wordpress_local -se "SELECT COUNT(*) FROM wp_posts WHERE post_type=\'post\' AND post_status=\'publish\'"';

        $process = Process::fromShellCommandline($command);
        $process->run();

        return (int) trim($process->getOutput());
    }

    private function remoteFileExists(string $path): bool
    {
        // Check file existence from local container via SSH to remote
        $command = sprintf(
            'docker exec movepress-local ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -i /root/.ssh/id_rsa root@wordpress-remote "[ -f %s ]"',
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

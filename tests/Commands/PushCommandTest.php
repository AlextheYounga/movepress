<?php

declare(strict_types=1);

namespace Movepress\Tests\Commands;

use Movepress\Commands\PushCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class PushCommandTest extends TestCase
{
    private CommandTester $commandTester;
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/movepress-test-' . uniqid();
        mkdir($this->testDir);

        $application = new Application();
        $application->add(new PushCommand());

        $command = $application->find('push');
        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function test_requires_source_and_destination_arguments(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);

        $this->commandTester->execute([]);
    }

    public function test_validates_source_environment_exists(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'nonexistent',
            'destination' => 'local',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Environment 'nonexistent' not found", $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_validates_destination_environment_exists(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'nonexistent',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("Environment 'nonexistent' not found", $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_fails_when_files_and_content_flags_used_together(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
            '--files' => true,
            '--content' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Cannot use --files with --content', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_fails_when_files_and_uploads_flags_used_together(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
            '--files' => true,
            '--uploads' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Cannot use --files with', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_displays_configuration_summary(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
            '--db' => true,
            '--dry-run' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Source: local', $output);
        $this->assertStringContainsString('Destination: production', $output);
        $this->assertStringContainsString('Database: ✓', $output);
        $this->assertStringContainsString('DRY RUN MODE', $output);
    }

    public function test_shows_all_files_when_files_flag_used(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
            '--files' => true,
            '--dry-run' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Files: ✓ (all)', $output);
    }

    public function test_shows_content_and_uploads_separately_when_both_specified(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
            '--content' => true,
            '--uploads' => true,
            '--dry-run' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Content: ✓', $output);
        $this->assertStringContainsString('Uploads: ✓', $output);
    }

    public function test_validates_environment_has_required_fields(): void
    {
        $yaml = <<<YAML
local:
  wordpress_path: "/var/www"

production:
  url: "https://example.com"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("missing 'url' configuration", $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    private function createMinimalConfig(): void
    {
        putenv('DB_NAME=test_db');
        putenv('DB_USER=root');
        putenv('DB_PASSWORD=pass');
        putenv('DB_HOST=localhost');
        putenv('PROD_HOST=example.com');
        putenv('PROD_USER=deploy');
        putenv('PROD_DB_NAME=prod_db');
        putenv('PROD_DB_USER=prod_user');
        putenv('PROD_DB_PASSWORD=prod_pass');

        $yaml = <<<YAML
global:
  exclude:
    - ".git/"

local:
  wordpress_path: "/var/www/local"
  url: "http://local.test"
  database:
    name: "\${DB_NAME}"
    user: "\${DB_USER}"
    password: "\${DB_PASSWORD}"
    host: "\${DB_HOST}"

production:
  wordpress_path: "/var/www/production"
  url: "https://example.com"
  database:
    name: "\${PROD_DB_NAME}"
    user: "\${PROD_DB_USER}"
    password: "\${PROD_DB_PASSWORD}"
    host: "localhost"
  ssh:
    host: "\${PROD_HOST}"
    user: "\${PROD_USER}"
    port: 22
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}

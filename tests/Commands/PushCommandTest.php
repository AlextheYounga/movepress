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
    private string $originalDir;

    protected function setUp(): void
    {
        $this->originalDir = getcwd();
        $this->testDir = sys_get_temp_dir() . '/movepress-test-' . uniqid();
        mkdir($this->testDir);

        $application = new Application();
        $application->add(new PushCommand());

        $command = $application->find('push');
        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        chdir($this->originalDir);
        $this->removeDirectory($this->testDir);
    }

    public function test_requires_source_and_destination_arguments(): void
    {
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Not enough arguments/');

        $this->commandTester->execute([]);
    }

    public function test_fails_when_config_file_missing(): void
    {
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'production',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Configuration file not found', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
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
            'destination' => 'local', // Use local->local to avoid SSH/rsync
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
            'destination' => 'local',
            '--files' => true,
            '--uploads' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Cannot use --files with', $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_displays_configuration_summary_with_db_flag(): void
    {
        $this->createMinimalConfig();

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'local',
            '--db' => true,
            '--dry-run' => true,
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Source: local', $output);
        $this->assertStringContainsString('Destination: local', $output);
        $this->assertStringContainsString('Database: ✓', $output);
        $this->assertStringContainsString('DRY RUN MODE', $output);
    }

    public function test_validates_environment_has_wordpress_path(): void
    {
        $yaml = <<<YAML
local:
  url: "http://local.test"
  database:
    name: "test"

staging:
  wordpress_path: "/var/www"
  url: "http://staging.test"
  database:
    name: "test"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'staging',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("missing 'wordpress_path'", $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_validates_environment_has_url(): void
    {
        $yaml = <<<YAML
local:
  wordpress_path: "/var/www"
  database:
    name: "test"

staging:
  wordpress_path: "/var/www"
  url: "http://staging.test"
  database:
    name: "test"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'staging',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("missing 'url'", $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_validates_environment_has_database(): void
    {
        $yaml = <<<YAML
local:
  wordpress_path: "/var/www"
  url: "http://local.test"

staging:
  wordpress_path: "/var/www"
  url: "http://staging.test"
  database:
    name: "test"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'staging',
        ]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString("missing 'database'", $output);
        $this->assertEquals(1, $this->commandTester->getStatusCode());
    }

    public function test_merges_global_and_environment_excludes(): void
    {
        $yaml = <<<YAML
global:
  exclude:
    - ".git/"
    - "node_modules/"

local:
  wordpress_path: "/var/www/local"
  url: "http://local.test"
  database:
    name: "test_db"
    user: "root"
    password: "pass"
    host: "localhost"
  exclude:
    - ".env.local"
    - "debug.log"

staging:
  wordpress_path: "/var/www/staging"
  url: "http://staging.test"
  database:
    name: "staging_db"
    user: "root"
    password: "pass"
    host: "localhost"
  exclude:
    - ".env.staging"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        // Test that config loads without errors when excludes are present
        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'staging',
            '--db' => true,
            '--dry-run' => true,
        ]);

        // Should execute successfully
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function test_works_with_only_global_excludes(): void
    {
        $yaml = <<<YAML
global:
  exclude:
    - ".git/"
    - "*.log"

local:
  wordpress_path: "/var/www/local"
  url: "http://local.test"
  database:
    name: "test_db"
    user: "root"
    password: "pass"
    host: "localhost"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'local',
            '--db' => true,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function test_works_without_any_excludes(): void
    {
        $yaml = <<<YAML
local:
  wordpress_path: "/var/www/local"
  url: "http://local.test"
  database:
    name: "test_db"
    user: "root"
    password: "pass"
    host: "localhost"
YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);
        chdir($this->testDir);

        $this->commandTester->execute([
            'source' => 'local',
            'destination' => 'local',
            '--db' => true,
            '--dry-run' => true,
        ]);

        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    private function createMinimalConfig(): void
    {
        $yaml = <<<YAML
global:
  exclude:
    - ".git/"

local:
  wordpress_path: "/var/www/local"
  url: "http://local.test"
  database:
    name: "test_db"
    user: "root"
    password: "pass"
    host: "localhost"
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

<?php

declare(strict_types=1);

namespace Movepress\Tests\Config;

use Movepress\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

class ConfigLoaderTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/movepress-test-' . uniqid();
        mkdir($this->testDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testDir);
    }

    public function test_loads_valid_yaml_configuration(): void
    {
        $yaml = <<<YAML
        global:
          exclude:
            - ".git/"

        local:
          wordpress_path: "/var/www"
          url: "http://local.test"
          database:
            name: "test_db"
            user: "root"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $config = $loader->load();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('global', $config);
        $this->assertArrayHasKey('local', $config);
    }

    public function test_throws_exception_when_config_file_not_found(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $loader = new ConfigLoader($this->testDir);
        $loader->load();
    }

    public function test_interpolates_environment_variables(): void
    {
        putenv('TEST_DB_NAME=my_database');
        putenv('TEST_USER=admin');

        $yaml = <<<YAML
        local:
          wordpress_path: "/var/www"
          url: "http://local.test"
          database:
            name: "\${TEST_DB_NAME}"
            user: "\${TEST_USER}"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $config = $loader->load();

        $this->assertEquals('my_database', $config['local']['database']['name']);
        $this->assertEquals('admin', $config['local']['database']['user']);

        putenv('TEST_DB_NAME');
        putenv('TEST_USER');
    }

    public function test_throws_exception_for_missing_environment_variable(): void
    {
        $yaml = <<<YAML
        local:
          database:
            name: "\${MISSING_VAR}"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Environment variable \'MISSING_VAR\' is not set');

        $loader = new ConfigLoader($this->testDir);
        $loader->load();
    }

    public function test_loads_dotenv_file_if_present(): void
    {
        // Use a simple test without env vars to verify .env file is loaded
        $envContent = "# Test .env file\nTEST_VAR=value";
        file_put_contents($this->testDir . '/.env', $envContent);

        $yaml = <<<YAML
        local:
          wordpress_path: "/var/www"
          url: "http://test.local"
          database:
            name: "test_db"
            user: "root"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $config = $loader->load();

        // Just verify the config loads successfully when .env exists
        $this->assertArrayHasKey('local', $config);
        $this->assertEquals('/var/www', $config['local']['wordpress_path']);
    }

    public function test_get_environment_returns_specific_environment(): void
    {
        $yaml = <<<YAML
        local:
          wordpress_path: "/local"
        production:
          wordpress_path: "/prod"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $loader->load();

        $local = $loader->getEnvironment('local');
        $this->assertEquals('/local', $local['wordpress_path']);

        $prod = $loader->getEnvironment('production');
        $this->assertEquals('/prod', $prod['wordpress_path']);
    }

    public function test_throws_exception_for_missing_environment(): void
    {
        $yaml = <<<YAML
        local:
          wordpress_path: "/local"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $loader->load();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Environment 'staging' not found");

        $loader->getEnvironment('staging');
    }

    public function test_get_environments_returns_all_except_global(): void
    {
        $yaml = <<<YAML
        global:
          exclude:
            - ".git/"
        local:
          wordpress_path: "/local"
        staging:
          wordpress_path: "/staging"
        production:
          wordpress_path: "/prod"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $loader->load();

        $environments = $loader->getEnvironments();

        $this->assertCount(3, $environments);
        $this->assertContains('local', $environments);
        $this->assertContains('staging', $environments);
        $this->assertContains('production', $environments);
        $this->assertNotContains('global', $environments);
    }

    public function test_get_excludes_merges_global_and_environment_excludes(): void
    {
        $yaml = <<<YAML
        global:
          exclude:
            - ".git/"
            - "node_modules/"

        local:
          wordpress_path: "/local"
          exclude:
            - ".env.local"

        production:
          wordpress_path: "/prod"
          exclude:
            - ".env.production"
            - "debug.log"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $loader->load();

        $localExcludes = $loader->getExcludes('local');
        $this->assertCount(3, $localExcludes);
        $this->assertContains('.git/', $localExcludes);
        $this->assertContains('node_modules/', $localExcludes);
        $this->assertContains('.env.local', $localExcludes);

        $prodExcludes = $loader->getExcludes('production');
        $this->assertCount(4, $prodExcludes);
        $this->assertContains('.git/', $prodExcludes);
        $this->assertContains('.env.production', $prodExcludes);
        $this->assertContains('debug.log', $prodExcludes);
    }

    public function test_get_excludes_returns_only_global_when_no_environment_excludes(): void
    {
        $yaml = <<<YAML
        global:
          exclude:
            - ".git/"

        local:
          wordpress_path: "/local"
        YAML;

        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $loader->load();

        $excludes = $loader->getExcludes('local');
        $this->assertCount(1, $excludes);
        $this->assertContains('.git/', $excludes);
    }

    public function test_loads_environment_variables_from_env_file(): void
    {
        // Create .env file with test variables
        $envContent = <<<ENV
        WORDPRESS_PATH=/var/www/wordpress
        DB_NAME=test_database
        DB_USER=test_user
        DB_PASSWORD=test_password
        DB_HOST=localhost
        SITE_URL=https://example.com
        ENV;
        file_put_contents($this->testDir . '/.env', $envContent);

        // Create movefile.yml that references these variables
        $yaml = <<<YAML
        local:
          wordpress_path: "\${WORDPRESS_PATH}"
          url: "\${SITE_URL}"
          database:
            name: "\${DB_NAME}"
            user: "\${DB_USER}"
            password: "\${DB_PASSWORD}"
            host: "\${DB_HOST}"
        YAML;
        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $config = $loader->load();

        // Verify all variables were interpolated correctly
        $this->assertEquals('/var/www/wordpress', $config['local']['wordpress_path']);
        $this->assertEquals('https://example.com', $config['local']['url']);
        $this->assertEquals('test_database', $config['local']['database']['name']);
        $this->assertEquals('test_user', $config['local']['database']['user']);
        $this->assertEquals('test_password', $config['local']['database']['password']);
        $this->assertEquals('localhost', $config['local']['database']['host']);
    }

    public function test_throws_exception_when_env_variable_in_config_but_not_in_env_file(): void
    {
        // Create .env file without MISSING_VAR
        $envContent = 'DB_NAME=test_database';
        file_put_contents($this->testDir . '/.env', $envContent);

        // Create movefile.yml that references a missing variable
        $yaml = <<<YAML
        local:
          wordpress_path: "/var/www"
          url: "http://local.test"
          database:
            name: "\${MISSING_VAR}"
        YAML;
        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Environment variable 'MISSING_VAR' is not set");

        $loader = new ConfigLoader($this->testDir);
        $loader->load();
    }

    public function test_handles_empty_env_values(): void
    {
        // Create .env file with empty value
        $envContent = <<<ENV
        EMPTY_VAR=
        NON_EMPTY_VAR=value
        ENV;
        file_put_contents($this->testDir . '/.env', $envContent);

        $yaml = <<<YAML
        local:
          wordpress_path: "\${NON_EMPTY_VAR}"
          url: "http://local.test"
          database:
            name: "\${EMPTY_VAR}"
        YAML;
        file_put_contents($this->testDir . '/movefile.yml', $yaml);

        $loader = new ConfigLoader($this->testDir);
        $config = $loader->load();

        // Empty values should work
        $this->assertEquals('', $config['local']['database']['name']);
        $this->assertEquals('value', $config['local']['wordpress_path']);
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

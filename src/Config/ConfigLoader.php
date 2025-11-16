<?php

declare(strict_types=1);

namespace Movepress\Config;

use Dotenv\Dotenv;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ConfigLoader
{
    private array $config = [];
    private string $workingDir;

    public function __construct(string $workingDir = null)
    {
        $this->workingDir = $workingDir ?? getcwd();
    }

    public function load(string $configPath = null): array
    {
        $configPath = $configPath ?? $this->workingDir . '/movefile.yml';

        if (!file_exists($configPath)) {
            throw new RuntimeException(
                "Configuration file not found: {$configPath}\n" .
                "Run 'movepress init' to create one."
            );
        }

        // Load .env if it exists
        $this->loadEnvironmentVariables();

        // Parse YAML
        $rawConfig = Yaml::parseFile($configPath);

        // Interpolate environment variables
        $this->config = $this->interpolateVariables($rawConfig);

        return $this->config;
    }

    public function getEnvironment(string $name): array
    {
        if (!isset($this->config[$name])) {
            throw new RuntimeException("Environment '{$name}' not found in configuration.");
        }

        return $this->config[$name];
    }

    public function getEnvironments(): array
    {
        // Return all environments except 'global'
        return array_filter(
            array_keys($this->config),
            fn($key) => $key !== 'global'
        );
    }

    public function getGlobalExcludes(): array
    {
        return $this->config['global']['exclude'] ?? [];
    }

    public function getExcludes(string $environment): array
    {
        $globalExcludes = $this->getGlobalExcludes();
        $envExcludes = $this->config[$environment]['exclude'] ?? [];

        return array_merge($globalExcludes, $envExcludes);
    }

    private function loadEnvironmentVariables(): void
    {
        $envPath = $this->workingDir;
        
        if (file_exists($envPath . '/.env')) {
            $dotenv = Dotenv::createImmutable($envPath);
            $dotenv->safeLoad();
        }
    }

    private function interpolateVariables(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([$this, 'interpolateVariables'], $data);
        }

        if (is_string($data)) {
            return preg_replace_callback(
                '/\$\{([A-Z_][A-Z0-9_]*)\}/',
                function ($matches) {
                    $varName = $matches[1];
                    $value = getenv($varName);
                    
                    if ($value === false) {
                        throw new RuntimeException(
                            "Environment variable '{$varName}' is not set. " .
                            "Check your .env file."
                        );
                    }
                    
                    return $value;
                },
                $data
            );
        }

        return $data;
    }
}

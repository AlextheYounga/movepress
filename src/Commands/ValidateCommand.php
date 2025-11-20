<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Console\MovepressStyle;
use Movepress\Config\ConfigLoader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ValidateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('validate')->setDescription('Validate movefile.yml configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new MovepressStyle($input, $output);
        $io->title('Validating Configuration');

        try {
            $config = new ConfigLoader();
            $config->load();

            $errors = [];
            $warnings = [];

            // Check environments
            $environments = $config->getEnvironments();

            if (empty($environments)) {
                $errors[] = 'No environments defined in configuration';
                $io->error($errors);
                return Command::FAILURE;
            }

            $io->section("Found {count($environments)} environment(s)");
            $io->listing($environments);

            // Validate each environment
            foreach ($environments as $name) {
                $env = $config->getEnvironment($name);
                $envErrors = $this->validateEnvironment($name, $env);
                $errors = array_merge($errors, $envErrors);
            }

            // Check for common issues
            $warnings = $this->checkCommonIssues($config, $environments);

            // Display results
            if (!empty($errors)) {
                $io->error('Configuration has errors:');
                $io->listing($errors);
                return Command::FAILURE;
            }

            if (!empty($warnings)) {
                $io->warning('Configuration has warnings:');
                $io->listing($warnings);
            }

            $io->success('Configuration is valid!');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to load configuration: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function validateEnvironment(string $name, array $env): array
    {
        $errors = [];

        // Required fields
        if (empty($env['wordpress_path'])) {
            $errors[] = "[{$name}] Missing required field: wordpress_path";
        }

        if (empty($env['url'])) {
            $errors[] = "[{$name}] Missing required field: url";
        }

        if (empty($env['database'])) {
            $errors[] = "[{$name}] Missing database configuration";
        } else {
            $db = $env['database'];

            if (empty($db['name'])) {
                $errors[] = "[{$name}] Missing database name";
            }

            if (empty($db['user'])) {
                $errors[] = "[{$name}] Missing database user";
            }

            if (empty($db['host'])) {
                $errors[] = "[{$name}] Missing database host";
            }
        }

        // Validate SSH config if present
        if (isset($env['ssh'])) {
            $ssh = $env['ssh'];

            if (empty($ssh['user'])) {
                $errors[] = "[{$name}] SSH configured but missing user";
            }

            if (empty($ssh['host'])) {
                $errors[] = "[{$name}] SSH configured but missing host";
            }

            // Check SSH key if specified
            if (isset($ssh['key'])) {
                $keyPath = $ssh['key'];
                // Expand ~ to home directory
                if (strpos($keyPath, '~') === 0) {
                    $home = getenv('HOME') ?: getenv('USERPROFILE');
                    $keyPath = str_replace('~', $home, $keyPath);
                }

                if (!file_exists($keyPath)) {
                    $errors[] = "[{$name}] SSH key file not found: {$ssh['key']}";
                }
            }
        }

        // Validate URL format
        if (isset($env['url']) && !filter_var($env['url'], FILTER_VALIDATE_URL)) {
            $errors[] = "[{$name}] Invalid URL format: {$env['url']}";
        }

        return $errors;
    }

    private function checkCommonIssues(ConfigLoader $config, array $environments): array
    {
        $warnings = [];

        // Check for identical URLs
        $urls = [];
        foreach ($environments as $name) {
            $env = $config->getEnvironment($name);
            if (isset($env['url'])) {
                if (isset($urls[$env['url']])) {
                    $warnings[] = "Environments '{$urls[$env['url']]}' and '{$name}' have the same URL: {$env['url']}";
                } else {
                    $urls[$env['url']] = $name;
                }
            }
        }

        // Check for missing password warnings
        foreach ($environments as $name) {
            $env = $config->getEnvironment($name);
            if (isset($env['database']) && empty($env['database']['password'])) {
                $warnings[] = "[{$name}] Database password not set (may require password prompt)";
            }
        }

        return $warnings;
    }
}

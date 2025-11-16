<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Config\ConfigLoader;
use Movepress\Services\DatabaseService;
use Movepress\Services\RsyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('status')
            ->setDescription('Show current configuration and system status')
            ->addArgument('environment', InputArgument::OPTIONAL, 'Specific environment to show (or all if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Movepress Status');

        try {
            $config = new ConfigLoader();
            $config->load();

            // Show system tools availability
            $this->showSystemTools($io);

            // Show environments
            $environmentName = $input->getArgument('environment');
            if ($environmentName) {
                $this->showEnvironment($io, $config, $environmentName);
            } else {
                $this->showAllEnvironments($io, $config);
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showSystemTools(SymfonyStyle $io): void
    {
        $io->section('System Tools');

        $tools = [
            ['rsync', RsyncService::isAvailable()],
            ['mysqldump', DatabaseService::isMysqldumpAvailable()],
            ['mysql', DatabaseService::isMysqlAvailable()],
            ['wp-cli', DatabaseService::isWpCliAvailable()],
        ];

        $rows = [];
        foreach ($tools as [$tool, $available]) {
            $rows[] = [
                $tool,
                $available ? '<fg=green>✓ Available</>' : '<fg=red>✗ Not found</>',
            ];
        }

        $io->table(['Tool', 'Status'], $rows);
    }

    private function showAllEnvironments(SymfonyStyle $io, ConfigLoader $config): void
    {
        $environments = $config->getEnvironments();

        if (empty($environments)) {
            $io->warning('No environments configured');
            return;
        }

        $io->section('Configured Environments');

        foreach ($environments as $name) {
            $env = $config->getEnvironment($name);
            $this->displayEnvironmentSummary($io, $name, $env);
            $io->newLine();
        }

        $io->note('Use: movepress status <environment> for detailed information');
    }

    private function showEnvironment(SymfonyStyle $io, ConfigLoader $config, string $name): void
    {
        $env = $config->getEnvironment($name);
        
        $io->section("Environment: {$name}");

        // Basic config
        $rows = [
            ['WordPress Path', $env['wordpress_path'] ?? '<fg=red>Not configured</>'],
            ['URL', $env['url'] ?? '<fg=red>Not configured</>'],
        ];

        // SSH config
        if (isset($env['ssh'])) {
            $ssh = $env['ssh'];
            $rows[] = ['SSH User', $ssh['user'] ?? '<fg=red>Not set</>'];
            $rows[] = ['SSH Host', $ssh['host'] ?? '<fg=red>Not set</>'];
            $rows[] = ['SSH Port', $ssh['port'] ?? '22'];
            $rows[] = ['SSH Key', $ssh['key'] ?? '<fg=yellow>None (password auth)</>'];
        } else {
            $rows[] = ['SSH', '<fg=yellow>Local environment</>'];
        }

        // Database config
        if (isset($env['database'])) {
            $db = $env['database'];
            $rows[] = ['Database Name', $db['name'] ?? '<fg=red>Not set</>'];
            $rows[] = ['Database User', $db['user'] ?? '<fg=red>Not set</>'];
            $rows[] = ['Database Host', $db['host'] ?? '<fg=red>Not set</>'];
            $rows[] = ['Database Password', isset($db['password']) ? '<fg=green>Set</>' : '<fg=yellow>Not set</>'];
        } else {
            $rows[] = ['Database', '<fg=red>Not configured</>'];
        }

        $io->table(['Setting', 'Value'], $rows);

        // Excludes
        $excludes = $config->getExcludes($name);
        if (!empty($excludes)) {
            $io->section('Exclude Patterns');
            $io->listing($excludes);
        }

        // Validation
        $this->validateEnvironment($io, $name, $env);
    }

    private function displayEnvironmentSummary(SymfonyStyle $io, string $name, array $env): void
    {
        $type = isset($env['ssh']) ? '<fg=blue>Remote</>' : '<fg=green>Local</>';
        $url = $env['url'] ?? '<fg=red>No URL</>';
        $db = isset($env['database']) ? '<fg=green>✓</>' : '<fg=red>✗</>';
        
        $io->writeln("  <fg=cyan>$name</> [{$type}] - {$url} - DB: {$db}");
    }

    private function validateEnvironment(SymfonyStyle $io, string $name, array $env): void
    {
        $io->section('Validation');

        $issues = [];

        if (empty($env['wordpress_path'])) {
            $issues[] = '✗ Missing wordpress_path';
        }

        if (empty($env['url'])) {
            $issues[] = '✗ Missing url';
        }

        if (empty($env['database'])) {
            $issues[] = '✗ Missing database configuration';
        } else {
            $db = $env['database'];
            if (empty($db['name'])) {
                $issues[] = '✗ Missing database name';
            }
            if (empty($db['user'])) {
                $issues[] = '✗ Missing database user';
            }
            if (empty($db['host'])) {
                $issues[] = '✗ Missing database host';
            }
        }

        if (isset($env['ssh'])) {
            $ssh = $env['ssh'];
            if (empty($ssh['user'])) {
                $issues[] = '✗ Missing SSH user';
            }
            if (empty($ssh['host'])) {
                $issues[] = '✗ Missing SSH host';
            }
        }

        if (empty($issues)) {
            $io->success('Configuration is valid');
        } else {
            $io->error('Configuration has issues:');
            $io->listing($issues);
        }
    }
}

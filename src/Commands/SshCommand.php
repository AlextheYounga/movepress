<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Console\MovepressStyle;
use Movepress\Config\ConfigLoader;
use Movepress\Services\SshService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ssh')
            ->setDescription('Test SSH connectivity to an environment')
            ->addArgument('environment', InputArgument::REQUIRED, 'Environment to test');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new MovepressStyle($input, $output);
        $environmentName = $input->getArgument('environment');

        $io->title("Testing SSH Connection: {$environmentName}");

        try {
            $config = new ConfigLoader();
            $config->load();

            $env = $config->getEnvironment($environmentName);

            if (!isset($env['ssh'])) {
                $io->error("Environment '{$environmentName}' is not configured for SSH (local environment)");
                return Command::FAILURE;
            }

            $sshConfig = $env['ssh'];
            $io->section('SSH Configuration');

            $rows = [
                ['Host', $sshConfig['host'] ?? '<fg=red>Not set</>'],
                ['User', $sshConfig['user'] ?? '<fg=red>Not set</>'],
                ['Port', $sshConfig['port'] ?? '22'],
                ['Key', $sshConfig['key'] ?? '<fg=yellow>None (password auth)</>'],
            ];

            $io->table(['Setting', 'Value'], $rows);

            // Test connection
            $io->section('Connection Test');
            $io->write('Testing connection... ');

            $sshService = new SshService($sshConfig);

            if ($sshService->testConnection()) {
                $io->writeln('<fg=green>✓ SUCCESS</>');
                $io->success("Successfully connected to {$environmentName}");
                return Command::SUCCESS;
            } else {
                $io->writeln('<fg=red>✗ FAILED</>');
                $io->error("Failed to connect to {$environmentName}");
                $io->note([
                    'Possible issues:',
                    '- SSH host is unreachable',
                    '- SSH credentials are incorrect',
                    '- SSH key file is incorrect or not readable',
                    '- Firewall is blocking the connection',
                    '- SSH service is not running on the remote host',
                ]);
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}

<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class InitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('init')->setDescription('Initialize a new Movepress configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Initialize Movepress Configuration');

        $workingDir = getcwd();
        $movefileTarget = $workingDir . '/movefile.yml';
        $envTarget = $workingDir . '/.env';

        try {
            // Check if files already exist
            if (file_exists($movefileTarget)) {
                if (!$io->confirm('movefile.yml already exists. Overwrite?', false)) {
                    $io->note('Skipping movefile.yml');
                } else {
                    $this->createMovefile($movefileTarget);
                    $io->success('Created movefile.yml');
                }
            } else {
                $this->createMovefile($movefileTarget);
                $io->success('Created movefile.yml');
            }

            if (file_exists($envTarget)) {
                if (!$io->confirm('.env already exists. Overwrite?', false)) {
                    $io->note('Skipping .env');
                } else {
                    $this->createEnvFile($envTarget);
                    $io->success('Created .env');
                }
            } else {
                $this->createEnvFile($envTarget);
                $io->success('Created .env');
            }

            $io->newLine();
            $io->section('Next Steps');
            $io->listing([
                'Edit movefile.yml to configure your environments',
                'Edit .env to add your credentials',
                'Run: movepress push local production --dry-run',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }

    private function createMovefile(string $target): void
    {
        $template = $this->getMovefileTemplate();

        if (file_put_contents($target, $template) === false) {
            throw new \RuntimeException("Failed to create movefile.yml at {$target}");
        }
    }

    private function createEnvFile(string $target): void
    {
        $template = $this->getEnvTemplate();

        if (file_put_contents($target, $template) === false) {
            throw new \RuntimeException("Failed to create .env at {$target}");
        }
    }

    private function getMovefileTemplate(): string
    {
        return <<<'YAML'
        # Movepress Configuration File
        # This file defines your WordPress environments and deployment settings

        # Global settings applied to all environments
        global:
          # Files and directories to exclude from sync (applies to all environments)
          exclude:
            - ".git/"
            - ".gitignore"
            - "node_modules/"
            - ".DS_Store"
            - "*.log"
            - "*.sql"
            - "*.sql.gz"
            - ".env*"
            - "wp-config-local.php"

        # Local environment configuration
        local:
          wordpress_path: "/path/to/local/wordpress"

          database:
            name: "${DB_NAME}"
            user: "${DB_USER}"
            password: "${DB_PASSWORD}"
            host: "${DB_HOST}"

          url: "http://local.test"

          exclude:
            - ".env.local"

        # Staging environment configuration
        staging:
          wordpress_path: "/var/www/staging.example.com/public"

          database:
            name: "${STAGING_DB_NAME}"
            user: "${STAGING_DB_USER}"
            password: "${STAGING_DB_PASSWORD}"
            host: "localhost"

          url: "https://staging.example.com"

          ssh:
            host: "${STAGING_HOST}"
            user: "${STAGING_USER}"
            port: 22

          exclude:
            - ".env.staging"

        # Production environment configuration
        production:
          wordpress_path: "/var/www/example.com/public"

          database:
            name: "${PROD_DB_NAME}"
            user: "${PROD_DB_USER}"
            password: "${PROD_DB_PASSWORD}"
            host: "localhost"

          url: "https://example.com"

          ssh:
            host: "${PROD_HOST}"
            user: "${PROD_USER}"
            port: 22

          exclude:
            - ".env.production"
            - "wp-config-production.php"

        YAML;
    }

    private function getEnvTemplate(): string
    {
        return <<<'ENV'
        # Local Database Configuration
        DB_NAME=local_db
        DB_USER=root
        DB_PASSWORD=root
        DB_HOST=localhost

        # Staging Environment
        STAGING_HOST=staging.example.com
        STAGING_USER=deployuser
        STAGING_DB_NAME=staging_db
        STAGING_DB_USER=staging_user
        STAGING_DB_PASSWORD=staging_password

        # Production Environment
        PROD_HOST=example.com
        PROD_USER=deployuser
        PROD_DB_NAME=prod_db
        PROD_DB_USER=prod_user
        PROD_DB_PASSWORD=prod_password

        ENV;
    }
}

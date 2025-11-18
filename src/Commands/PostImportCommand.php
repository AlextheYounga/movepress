<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Application;
use Search_Replace_Command;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PostImportCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('post-import')
            ->setDescription('Finalize database after import by performing search-replace')
            ->addArgument('old-url', InputArgument::REQUIRED, 'Old URL to search for')
            ->addArgument('new-url', InputArgument::REQUIRED, 'New URL to replace with');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $oldUrl = $input->getArgument('old-url');
        $newUrl = $input->getArgument('new-url');

        try {
            Application::loadWpCliClasses();

            define('WP_USE_THEMES', false);
            $this->configureServerContext($newUrl);
            $this->hydrateDatabaseEnvSuperglobals();

            $wordpressPath = getcwd();
            if (!file_exists($wordpressPath . '/wp-load.php')) {
                $io->error("WordPress not found in current directory: {$wordpressPath}");
                return Command::FAILURE;
            }

            require_once $wordpressPath . '/wp-load.php';

            if (!defined('WP_CLI')) {
                define('WP_CLI', true);
            }

            // Initialize WP_CLI Runner with minimal config using reflection
            $runner = \WP_CLI::get_runner();
            $reflection = new \ReflectionClass($runner);
            $configProperty = $reflection->getProperty('config');
            $configProperty->setAccessible(true);
            $configProperty->setValue($runner, ['quiet' => true]);
            $io->text("Performing search-replace: {$oldUrl} → {$newUrl}");

            $searchReplace = new Search_Replace_Command();
            $searchReplace->__invoke(
                [$oldUrl, $newUrl],
                [
                    'skip-columns' => 'guid',
                    'quiet' => true,
                    'all-tables' => true,
                ],
            );

            $io->success('Post-import finalization complete');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Post-import failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function configureServerContext(string $targetUrl): void
    {
        $parsed = parse_url($targetUrl);
        $hostOnly = $parsed !== false && isset($parsed['host']) ? $parsed['host'] : 'localhost';
        $scheme = $parsed !== false && isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : 'http';
        $isHttps = $scheme === 'https';
        $port = $parsed !== false && isset($parsed['port']) ? (int) $parsed['port'] : ($isHttps ? 443 : 80);
        $hostHeader = $hostOnly;
        if ($parsed !== false && isset($parsed['port'])) {
            $hostHeader .= ':' . $parsed['port'];
        }

        $_SERVER['HTTP_HOST'] = $hostHeader;
        $_SERVER['SERVER_NAME'] = $hostOnly;
        $_SERVER['SERVER_PORT'] = (string) $port;
        $_SERVER['REQUEST_SCHEME'] = $isHttps ? 'https' : 'http';
        $_SERVER['HTTPS'] = $isHttps ? 'on' : 'off';
    }

    private function hydrateDatabaseEnvSuperglobals(): void
    {
        $keys = [
            'WORDPRESS_DB_HOST',
            'WORDPRESS_DB_USER',
            'WORDPRESS_DB_PASSWORD',
            'WORDPRESS_DB_NAME',
            'DB_HOST',
            'DB_USER',
            'DB_PASSWORD',
            'DB_NAME',
            'MYSQL_HOST',
            'MYSQL_USER',
            'MYSQL_PASSWORD',
            'MYSQL_DATABASE',
        ];

        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value === false) {
                continue;
            }
            $_ENV[$key] = $value;
        }
    }
}

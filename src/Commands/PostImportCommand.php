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
            $_SERVER['HTTP_HOST'] = 'localhost';

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
}

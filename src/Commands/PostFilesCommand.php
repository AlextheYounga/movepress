<?php

declare(strict_types=1);

namespace Movepress\Commands;

use Movepress\Services\FileSearchReplaceService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class PostFilesCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('post-files')
            ->setDescription('Update hardcoded URLs in synced files after push/pull operations')
            ->addArgument('old-url', InputArgument::REQUIRED, 'Old URL to search for')
            ->addArgument('new-url', InputArgument::REQUIRED, 'New URL to replace with')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Path (relative or absolute) to scan');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $oldUrl = $input->getArgument('old-url');
        $newUrl = $input->getArgument('new-url');
        $wordpressPath = getcwd();

        try {
            $scanPath = $this->resolveScanPath($wordpressPath, (string) $input->getOption('path'));
            $service = new FileSearchReplaceService($output->isVeryVerbose() || $output->isDebug());
            $io->text("Updating files in {$scanPath}");

            $result = $service->replaceInPath($scanPath, $oldUrl, $newUrl);

            $io->success(
                sprintf('Processed %d files, updated %d files', $result['filesChecked'], $result['filesModified']),
            );

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Post-files processing failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function resolveScanPath(string $wordpressPath, ?string $pathOption): string
    {
        if ($pathOption === null || $pathOption === '') {
            return $wordpressPath;
        }

        $resolved = str_starts_with($pathOption, '/') ? $pathOption : $wordpressPath . '/' . ltrim($pathOption, '/');

        if (!is_dir($resolved)) {
            throw new \RuntimeException("Scan path not found: {$resolved}");
        }

        return $resolved;
    }
}

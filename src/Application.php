<?php

declare(strict_types=1);

namespace Movepress;

use Movepress\Commands\BackupCommand;
use Movepress\Commands\GitSetupCommand;
use Movepress\Commands\InitCommand;
use Movepress\Commands\PullCommand;
use Movepress\Commands\PushCommand;
use Movepress\Commands\SshCommand;
use Movepress\Commands\StatusCommand;
use Movepress\Commands\ValidateCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    private const VERSION = '0.1.0';
    private const NAME = 'Movepress';
    private static bool $wpCliLoaded = false;

    public function __construct()
    {
        self::loadWpCliClasses();
        parent::__construct(self::NAME, self::VERSION);

        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->add(new PushCommand());
        $this->add(new PullCommand());
        $this->add(new InitCommand());
        $this->add(new StatusCommand());
        $this->add(new ValidateCommand());
        $this->add(new SshCommand());
        $this->add(new BackupCommand());
        $this->add(new GitSetupCommand());
    }

    public static function loadWpCliClasses(): void
    {
        if (self::$wpCliLoaded) {
            return;
        }

        $vendorBase = dirname(__DIR__) . '/vendor';
        $requiredFiles = [
            $vendorBase . '/wp-cli/wp-cli/php/class-wp-cli-command.php',
            $vendorBase . '/wp-cli/search-replace-command/src/WP_CLI/SearchReplacer.php',
            $vendorBase . '/wp-cli/search-replace-command/src/Search_Replace_Command.php',
        ];

        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                throw new \RuntimeException("Bundled wp-cli file missing: {$file}");
            }

            require_once $file;
        }

        self::$wpCliLoaded = true;
    }
}

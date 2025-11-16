<?php

declare(strict_types=1);

namespace Movepress;

use Movepress\Commands\InitCommand;
use Movepress\Commands\PullCommand;
use Movepress\Commands\PushCommand;
use Symfony\Component\Console\Application as ConsoleApplication;

class Application extends ConsoleApplication
{
    private const VERSION = '0.1.0';
    private const NAME = 'Movepress';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->registerCommands();
    }

    private function registerCommands(): void
    {
        $this->add(new PushCommand());
        $this->add(new PullCommand());
        $this->add(new InitCommand());
    }
}

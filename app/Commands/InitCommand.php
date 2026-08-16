<?php

namespace App\Commands;

use App\Support\DefaultMaintainerConfiguration;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Filesystem\Filesystem;
use LaravelZero\Framework\Commands\Command;

#[Signature('init {--force : Overwrite an existing maintainer.json file}')]
#[Description('Create the Maintainer configuration file')]
final class InitCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        Filesystem $files,
        ProjectPath $projectPath,
        DefaultMaintainerConfiguration $defaults,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $path = $projectRoot.DIRECTORY_SEPARATOR.'maintainer.json';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->error('maintainer.json already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        if ($files->put($path, $defaults->contents()) === false) {
            $this->error('Unable to write maintainer.json.');

            return self::FAILURE;
        }

        $this->info('Created maintainer.json.');

        return self::SUCCESS;
    }
}

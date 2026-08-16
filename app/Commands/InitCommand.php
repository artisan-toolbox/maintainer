<?php

namespace App\Commands;

use App\Support\Configuration\DefaultMaintainerConfiguration;
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
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $path = $projectRoot.DIRECTORY_SEPARATOR.'maintainer.json';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error('maintainer.json already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        if ($files->put($path, $defaults->contents()) === false) {
            $this->components->error('Unable to write maintainer.json.');

            return self::FAILURE;
        }

        $this->components->success('Created maintainer.json.');

        return self::SUCCESS;
    }
}

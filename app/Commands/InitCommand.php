<?php

namespace App\Commands;

use App\Support\Configuration\DefaultMaintainerConfiguration;
use App\Support\Configuration\DefaultMaintainerSecrets;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Filesystem\Filesystem;
use LaravelZero\Framework\Commands\Command;

#[Signature('init {--force : Overwrite an existing maintainer.json file}')]
#[Description('Create the Maintainer configuration and secrets files')]
final class InitCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        Filesystem $files,
        ProjectPath $projectPath,
        DefaultMaintainerConfiguration $defaults,
        DefaultMaintainerSecrets $defaultSecrets,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $path = $projectRoot.DIRECTORY_SEPARATOR.'maintainer.json';
        $secretsPath = $projectRoot.DIRECTORY_SEPARATOR.'maintainer_secrets.json';

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error('maintainer.json already exists. Use --force to overwrite it.');

            return self::FAILURE;
        }

        if ($files->put($path, $defaults->contents()) === false) {
            $this->components->error('Unable to write maintainer.json.');

            return self::FAILURE;
        }

        if (! $this->ignoreSecretsFile($files, $projectRoot)) {
            $this->components->error('Unable to add maintainer_secrets.json to .gitignore.');

            return self::FAILURE;
        }

        if (! $files->exists($secretsPath)) {
            if ($files->put($secretsPath, $defaultSecrets->contents()) === false) {
                $this->components->error('Unable to write maintainer_secrets.json.');

                return self::FAILURE;
            }

            $this->components->twoColumnDetail('Created secrets', $secretsPath);
        } else {
            $this->components->warn('maintainer_secrets.json already exists and was not overwritten.');
        }

        $this->components->success('Created Maintainer configuration and protected its secrets file.');

        return self::SUCCESS;
    }

    private function ignoreSecretsFile(Filesystem $files, string $projectRoot): bool
    {
        $path = $projectRoot.DIRECTORY_SEPARATOR.'.gitignore';
        $contents = $files->exists($path) ? $files->get($path) : '';
        $lines = preg_split('/\R/', $contents) ?: [];

        if (in_array('maintainer_secrets.json', array_map(trim(...), $lines), true)) {
            return true;
        }

        if ($contents !== '' && ! str_ends_with($contents, "\n") && ! str_ends_with($contents, "\r")) {
            $contents .= PHP_EOL;
        }

        return $files->put($path, $contents.'maintainer_secrets.json'.PHP_EOL) !== false;
    }
}

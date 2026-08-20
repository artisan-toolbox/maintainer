<?php

namespace App\Commands;

use App\Support\Configuration\DefaultMaintainerConfiguration;
use App\Support\Configuration\DefaultMaintainerSecrets;
use App\Support\Configuration\LegacyJsonConfigurationLoader;
use App\Support\Configuration\PhpConfigurationExporter;
use App\Support\Configuration\UserConfigurationPath;
use App\Support\Git\GitignoreManager;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Filesystem\Filesystem;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

#[Signature('init {--force : Overwrite the existing Maintainer user configuration file}')]
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
        LegacyJsonConfigurationLoader $legacyLoader,
        PhpConfigurationExporter $exporter,
        GitignoreManager $gitignore,
        UserConfigurationPath $userConfigurationPath,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $path = $userConfigurationPath->path('maintainer');
        $secretsPath = $userConfigurationPath->path('maintainer_secrets');
        $configurationRelativePath = $userConfigurationPath->relativePath('maintainer');
        $secretsRelativePath = $userConfigurationPath->relativePath('maintainer_secrets');
        $legacyPath = $userConfigurationPath->legacyPath('maintainer.json');
        $legacySecretsPath = $userConfigurationPath->legacyPath('maintainer_secrets.json');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error("{$configurationRelativePath} already exists. Use --force to overwrite it.");

            return self::FAILURE;
        }

        try {
            $files->ensureDirectoryExists(dirname($path));
            $migratedConfiguration = ! $files->exists($path) && $files->isFile($legacyPath);
            $configurationContents = $migratedConfiguration
                ? $exporter->export($legacyLoader->load($legacyPath, 'maintainer.json'))
                : $defaults->contents();

            throw_if($files->put($path, $configurationContents) === false, RuntimeException::class, "Unable to write {$configurationRelativePath}.");

            if ($migratedConfiguration) {
                throw_unless($files->delete($legacyPath), RuntimeException::class, 'Unable to remove the migrated maintainer.json file.');
                $this->components->twoColumnDetail('Migrated configuration', $path);
            }

            $gitignore->add($projectRoot, [$secretsRelativePath]);

            if (! $files->exists($secretsPath)) {
                $migratedSecrets = $files->isFile($legacySecretsPath);
                $secretsContents = $migratedSecrets
                    ? $this->migrateSecrets(
                        $legacyLoader->load($legacySecretsPath, 'maintainer_secrets.json'),
                        $exporter,
                    )
                    : $defaultSecrets->contents();

                throw_if($files->put($secretsPath, $secretsContents) === false, RuntimeException::class, "Unable to write {$secretsRelativePath}.");

                if ($migratedSecrets) {
                    throw_unless($files->delete($legacySecretsPath), RuntimeException::class, 'Unable to remove the migrated maintainer_secrets.json file.');
                    $this->components->twoColumnDetail('Migrated secrets', $secretsPath);
                } else {
                    $this->components->twoColumnDetail('Created secrets', $secretsPath);
                }
            } else {
                $this->components->warn("{$secretsRelativePath} already exists and was not overwritten.");
            }
        } catch (RuntimeException $exception) {
            $this->components->error("Unable to initialize Maintainer: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->success("Created Maintainer configuration at {$configurationRelativePath} and protected its secrets file.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $secrets
     */
    private function migrateSecrets(array $secrets, PhpConfigurationExporter $exporter): string
    {
        if (array_key_exists('key', $secrets)) {
            return $exporter->export($secrets);
        }

        if ($secrets === []) {
            return "<?php\n\nreturn [\n    'key' => env('APP_KEY'),\n];\n";
        }

        return str_replace(
            "return [\n",
            "return [\n    'key' => env('APP_KEY'),\n",
            $exporter->export($secrets),
        );
    }
}

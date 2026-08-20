<?php

namespace App\Commands\Configuration;

use App\Support\Configuration\ConfigurationFilePublisher;
use App\Support\Configuration\PublishableConfiguration;
use App\Support\Git\GitignoreManager;
use App\Support\ProjectPath;
use App\Support\Quality\LaravelProjectType;
use App\Support\Quality\LaravelProjectTypeDetector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Filesystem\Filesystem;
use LaravelZero\Framework\Commands\Command;
use LogicException;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('config:publish')]
#[Description('Publish selected project configuration files')]
final class PublishConfigurationCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        LaravelProjectTypeDetector $projectTypeDetector,
        ConfigurationFilePublisher $publisher,
        GitignoreManager $gitignore,
        Filesystem $files,
    ): int {
        if (! $this->input->isInteractive()) {
            $this->components->error('The config:publish command requires interactive input to select configuration files and confirm overwrites.');

            return self::FAILURE;
        }

        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $configurations = $this->selectConfigurations($publisher);
        $projectType = $this->selectProjectType($configurations, $projectTypeDetector->detect($projectRoot));
        $shouldIgnore = confirm(
            label: 'Add the selected configuration files to .gitignore?',
            default: true,
        );

        try {
            foreach ($configurations as $configuration) {
                $destination = $publisher->destination($configuration, $projectRoot);
                $relativeDestination = $publisher->relativeDestination($configuration);
                $overwrite = false;

                if ($files->exists($destination)) {
                    $overwrite = confirm(
                        label: "ARE YOU SURE you want to overwrite {$relativeDestination}?",
                        default: false,
                    );

                    if (! $overwrite) {
                        $this->components->warn("Kept existing {$relativeDestination}; it was not overwritten.");

                        continue;
                    }
                }

                $email = $configuration === PublishableConfiguration::MaintainerSecrets
                    ? $this->sshKeyEmail()
                    : null;
                $path = $publisher->publish($configuration, $projectRoot, $projectType, $overwrite, $email);
                $this->components->twoColumnDetail("Published {$relativeDestination}", $path);
            }

            if ($shouldIgnore) {
                $added = $gitignore->add(
                    $projectRoot,
                    array_map(
                        $publisher->relativeDestination(...),
                        $configurations,
                    ),
                );

                if ($added === []) {
                    $this->components->info('The selected configuration files are already listed in .gitignore.');
                } else {
                    $this->components->twoColumnDetail('Updated .gitignore', implode(', ', $added));
                }
            }
        } catch (RuntimeException $exception) {
            $this->components->error("Unable to publish configuration files: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->success('Configuration publishing completed.');

        return self::SUCCESS;
    }

    /**
     * @return list<PublishableConfiguration>
     */
    private function selectConfigurations(ConfigurationFilePublisher $publisher): array
    {
        $options = [];

        foreach (PublishableConfiguration::cases() as $configuration) {
            $options[$configuration->value] = $configuration->isMaintainerConfiguration()
                ? $configuration->label().' ('.$publisher->relativeDestination($configuration).')'
                : $configuration->label();
        }

        $selected = multiselect(
            label: 'Which configuration files would you like to publish?',
            options: $options,
            required: 'Select at least one configuration file to publish.',
        );

        $configurations = [];

        foreach ($selected as $value) {
            throw_unless(is_string($value), LogicException::class, 'The selected configuration is invalid.');

            $configurations[] = PublishableConfiguration::from($value);
        }

        return $configurations;
    }

    /**
     * @param  list<PublishableConfiguration>  $configurations
     */
    private function selectProjectType(
        array $configurations,
        LaravelProjectType $detectedProjectType,
    ): LaravelProjectType {
        $requiresProjectType = array_any(
            $configurations,
            static fn (PublishableConfiguration $configuration): bool => $configuration->hasProjectSpecificTemplate(),
        );

        if (! $requiresProjectType) {
            return $detectedProjectType;
        }

        $selected = select(
            label: 'Which project type should the selected configuration files target?',
            options: [
                LaravelProjectType::Application->value => LaravelProjectType::Application->label(),
                LaravelProjectType::Package->value => LaravelProjectType::Package->label(),
            ],
            default: $detectedProjectType->value,
        );

        return LaravelProjectType::from($selected);
    }

    private function sshKeyEmail(): string
    {
        return text(
            label: 'Which email should identify the Maintainer SSH key?',
            placeholder: 'developer@example.com',
            required: 'An email address is required to generate the SSH key.',
            validate: static fn (string $email): ?string => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? null
                : 'Enter a valid email address.',
        );
    }
}

<?php

namespace App\Support\Configuration;

use App\Support\Quality\LaravelProjectType;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

use function Illuminate\Filesystem\join_paths;

final readonly class ConfigurationFilePublisher
{
    public function __construct(
        private Filesystem $files,
        private ?UserConfigurationPath $userConfigurationPath = null,
        private ?string $templateRoot = null,
        private ?MaintainerSecretsTemplate $maintainerSecretsTemplate = null,
    ) {}

    public function destination(PublishableConfiguration $configuration, string $projectRoot): string
    {
        if ($configuration->isMaintainerConfiguration()) {
            return $this->userConfigurationPath($configuration)->path($this->userConfigurationName($configuration));
        }

        return join_paths($projectRoot, $configuration->filename());
    }

    public function relativeDestination(PublishableConfiguration $configuration): string
    {
        if ($configuration->isMaintainerConfiguration()) {
            return $this->userConfigurationPath($configuration)->relativePath($this->userConfigurationName($configuration));
        }

        return $configuration->filename();
    }

    public function publish(
        PublishableConfiguration $configuration,
        string $projectRoot,
        LaravelProjectType $projectType,
        bool $overwrite = false,
        ?string $email = null,
    ): string {
        $destination = $this->destination($configuration, $projectRoot);

        throw_if(
            $this->files->exists($destination) && ! $overwrite,
            RuntimeException::class,
            "{$configuration->filename()} already exists and will not be overwritten.",
        );

        $template = $configuration->isMaintainerConfiguration()
            ? config_path($configuration->filename())
            : $configuration->templatePath(
                $this->templateRoot ?? resource_path(),
                $projectType,
            );

        throw_unless(
            $this->files->isFile($template),
            RuntimeException::class,
            "The {$configuration->configurationLabel()} configuration template could not be found.",
        );

        $this->files->ensureDirectoryExists(dirname($destination));

        if ($configuration === PublishableConfiguration::MaintainerSecrets) {
            throw_if($email === null || $email === '', RuntimeException::class, 'An email address is required to generate the Maintainer SSH key.');
            throw_if($this->maintainerSecretsTemplate === null, RuntimeException::class, 'The Maintainer secrets publisher is unavailable.');
            $contents = $this->maintainerSecretsTemplate->contents($email);
        } else {
            $contents = $this->files->get($template);
        }

        throw_if(
            $this->files->put($destination, $this->format($contents)) === false,
            RuntimeException::class,
            "Unable to create {$configuration->filename()}.",
        );

        return $destination;
    }

    private function userConfigurationPath(PublishableConfiguration $configuration): UserConfigurationPath
    {
        throw_if(
            $this->userConfigurationPath === null,
            RuntimeException::class,
            "Unable to resolve the destination for {$configuration->configurationLabel()}.",
        );

        return $this->userConfigurationPath;
    }

    private function format(string $contents): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $contents = preg_replace('/[\t ]+$/m', '', $contents) ?? $contents;

        return rtrim($contents)."\n";
    }

    private function userConfigurationName(PublishableConfiguration $configuration): string
    {
        $name = $configuration->userConfigurationName();

        throw_if($name === null, RuntimeException::class, "{$configuration->configurationLabel()} is not a Maintainer user configuration.");

        return $name;
    }
}

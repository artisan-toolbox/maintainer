<?php

namespace App\Support\Quality;

use App\Support\Configuration\ConfigurationFilePublisher;
use App\Support\Configuration\PublishableConfiguration;
use Illuminate\Filesystem\Filesystem;

use function Illuminate\Filesystem\join_paths;

final readonly class QualityConfigurationManager
{
    public function __construct(
        private Filesystem $files,
        private ?string $templateRoot = null,
    ) {}

    public function find(QualityTool $tool, string $projectRoot): ?string
    {
        foreach ($tool->configurationFilenames() as $filename) {
            $path = join_paths($projectRoot, $filename);

            if ($this->files->isFile($path)) {
                return $path;
            }
        }

        return null;
    }

    public function install(
        QualityTool $tool,
        string $projectRoot,
        LaravelProjectType $projectType,
    ): string {
        return new ConfigurationFilePublisher(
            files: $this->files,
            templateRoot: $this->templateRoot,
        )->publish(
            PublishableConfiguration::from($tool->value),
            $projectRoot,
            $projectType,
        );
    }
}

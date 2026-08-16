<?php

namespace App\Support\Quality;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class QualityConfigurationManager
{
    public function __construct(
        private Filesystem $files,
        private ?string $templateRoot = null,
    ) {}

    public function find(QualityTool $tool, string $projectRoot): ?string
    {
        foreach ($tool->configurationFilenames() as $filename) {
            $path = $projectRoot.DIRECTORY_SEPARATOR.$filename;

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
        $destination = $projectRoot.DIRECTORY_SEPARATOR.$tool->defaultConfigurationFilename();

        throw_if(
            $this->files->exists($destination),
            RuntimeException::class,
            "{$tool->defaultConfigurationFilename()} already exists and will not be overwritten.",
        );

        $template = $this->templatePath($tool, $projectType);

        throw_unless(
            $this->files->isFile($template),
            RuntimeException::class,
            "The {$tool->label()} configuration template could not be found.",
        );

        throw_unless(
            $this->files->copy($template, $destination),
            RuntimeException::class,
            "Unable to create {$tool->defaultConfigurationFilename()}.",
        );

        return $destination;
    }

    private function templatePath(QualityTool $tool, LaravelProjectType $projectType): string
    {
        $templateRoot = $this->templateRoot ?? dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources';

        if ($tool === QualityTool::Pint) {
            return $templateRoot.DIRECTORY_SEPARATOR.'pint.json';
        }

        $directory = $projectType === LaravelProjectType::Application
            ? ''
            : 'laravel-package'.DIRECTORY_SEPARATOR;

        return $templateRoot.DIRECTORY_SEPARATOR.$directory.$tool->defaultConfigurationFilename();
    }
}

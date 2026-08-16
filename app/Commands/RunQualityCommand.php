<?php

namespace App\Commands;

use App\Support\ProjectPath;
use App\Support\Quality\LaravelProjectType;
use App\Support\Quality\LaravelProjectTypeDetector;
use App\Support\Quality\QualityConfigurationManager;
use App\Support\Quality\QualityTool;
use App\Support\Quality\QualityToolRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

#[Signature('quality')]
#[Description('Run Pint, Rector, and PHPStan with the project configuration')]
final class RunQualityCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        QualityConfigurationManager $configurations,
        LaravelProjectTypeDetector $projectTypeDetector,
        QualityToolRunner $runner,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $configurationPaths = $this->configurationPaths(
                $projectRoot,
                $configurations,
                $projectTypeDetector,
            );

            if ($configurationPaths === null) {
                return self::FAILURE;
            }

            foreach (QualityTool::cases() as $tool) {
                $this->components->twoColumnDetail("Running {$tool->label()}", $configurationPaths[$tool->value]);

                $exitCode = $runner->run(
                    $tool,
                    $projectRoot,
                    $configurationPaths[$tool->value],
                    function (string $output): void {
                        $this->output->write($output);
                    },
                );

                if ($exitCode !== self::SUCCESS) {
                    $this->components->error("{$tool->label()} failed with exit code {$exitCode}.");

                    return $exitCode;
                }
            }
        } catch (RuntimeException $exception) {
            $this->components->error("Unable to run the quality workflow: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->success('Pint, Rector, and PHPStan completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>|null
     */
    private function configurationPaths(
        string $projectRoot,
        QualityConfigurationManager $configurations,
        LaravelProjectTypeDetector $projectTypeDetector,
    ): ?array {
        $paths = [];
        $detectedProjectType = $projectTypeDetector->detect($projectRoot);

        foreach (QualityTool::cases() as $tool) {
            $path = $configurations->find($tool, $projectRoot);

            if ($path !== null) {
                $paths[$tool->value] = $path;

                continue;
            }

            if (! $this->input->isInteractive()) {
                $filename = $tool->defaultConfigurationFilename();
                $this->components->error("{$tool->label()} configuration is missing. Run the quality command interactively to create {$filename}, or add your own configuration.");

                return null;
            }

            if (! confirm(
                label: "No {$tool->label()} configuration was found. Create the recommended configuration?",
                default: true,
            )) {
                $this->components->error("{$tool->label()} requires a project configuration before it can run.");

                return null;
            }

            $projectType = $tool === QualityTool::Pint
                ? $detectedProjectType
                : $this->selectProjectType($tool, $detectedProjectType);

            $paths[$tool->value] = $configurations->install($tool, $projectRoot, $projectType);
            $this->components->twoColumnDetail("Created {$tool->label()} configuration", $paths[$tool->value]);
        }

        return $paths;
    }

    private function selectProjectType(
        QualityTool $tool,
        LaravelProjectType $detectedProjectType,
    ): LaravelProjectType {
        $selected = select(
            label: "Which project type should the {$tool->label()} configuration target?",
            options: [
                LaravelProjectType::Application->value => LaravelProjectType::Application->label(),
                LaravelProjectType::Package->value => LaravelProjectType::Package->label(),
            ],
            default: $detectedProjectType->value,
        );

        return LaravelProjectType::from($selected);
    }
}

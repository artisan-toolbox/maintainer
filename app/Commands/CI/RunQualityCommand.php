<?php

namespace App\Commands\CI;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Git\GitWorkingTree;
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

#[Signature('quality {--tool=* : Run only selected tools: pint, rector, phpstan, or pest}')]
#[Description('Run Pint, Rector, PHPStan, and Pest with the project configuration')]
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
        GitWorkingTree $workingTree,
        MaintainerConfiguration $maintainerConfiguration,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $tools = $this->selectedTools();
            $configurationPaths = $this->configurationPaths(
                $projectRoot,
                $configurations,
                $projectTypeDetector,
                $tools,
            );

            if ($configurationPaths === null) {
                return self::FAILURE;
            }

            foreach ($tools as $tool) {
                $this->components->twoColumnDetail("Running {$tool->label()}", $configurationPaths[$tool->value]);

                $exitCode = $runner->run(
                    $tool,
                    $projectRoot,
                    $configurationPaths[$tool->value],
                    function (string $output): void {
                        $this->output->write($output);
                    },
                    $this->additionalArguments($tool, $maintainerConfiguration),
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

        $this->components->success($this->successMessage($tools));

        if ($this->input->isInteractive() && $this->shouldCreateCommit($projectRoot, $workingTree)) {
            return $this->call('commit');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<QualityTool>
     */
    private function selectedTools(): array
    {
        $selected = $this->option('tool');

        if ($selected === []) {
            return QualityTool::cases();
        }

        $tools = [];

        foreach ($selected as $value) {
            $tool = is_string($value)
                ? QualityTool::tryFrom($value)
                : null;

            throw_if($tool === null, RuntimeException::class, 'Every selected quality tool must be pint, rector, phpstan, or pest.');

            $tools[$tool->value] = $tool;
        }

        return array_values($tools);
    }

    /**
     * @param  list<QualityTool>  $tools
     */
    private function successMessage(array $tools): string
    {
        if (count($tools) === count(QualityTool::cases())) {
            return 'Pint, Rector, PHPStan, and Pest completed successfully.';
        }

        $labels = array_map(
            static fn (QualityTool $tool): string => $tool->label(),
            $tools,
        );

        if (count($labels) === 1) {
            return "{$labels[0]} completed successfully.";
        }

        $lastLabel = array_pop($labels);
        $separator = count($labels) === 1 ? ' and ' : ', and ';

        return implode(', ', $labels).$separator.$lastLabel.' completed successfully.';
    }

    /**
     * @param  list<QualityTool>  $tools
     * @return array<string, string>|null
     */
    private function configurationPaths(
        string $projectRoot,
        QualityConfigurationManager $configurations,
        LaravelProjectTypeDetector $projectTypeDetector,
        array $tools,
    ): ?array {
        $paths = [];
        $detectedProjectType = $projectTypeDetector->detect($projectRoot);

        foreach ($tools as $tool) {
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

    private function shouldCreateCommit(string $projectRoot, GitWorkingTree $workingTree): bool
    {
        try {
            if ($workingTree->isClean($projectRoot)) {
                return false;
            }
        } catch (RuntimeException $exception) {
            $this->components->warn("Unable to inspect Git changes, so the commit suggestion was skipped: {$exception->getMessage()}");

            return false;
        }

        return confirm(
            label: 'The project has changes. Would you like to create a commit now?',
            default: true,
        );
    }

    /**
     * @return list<string>
     */
    private function additionalArguments(
        QualityTool $tool,
        MaintainerConfiguration $configuration,
    ): array {
        if ($tool !== QualityTool::PhpStan) {
            return [];
        }

        $memoryLimit = $configuration->get('quality.phpstan.memory_limit');

        throw_unless(
            is_string($memoryLimit) && preg_match('/^(?:-1|[1-9]\d*[KMG]?)$/i', $memoryLimit) === 1,
            RuntimeException::class,
            'quality.phpstan.memory_limit must be -1, a byte count, or a value such as 512M or 2G.',
        );

        return ["--memory-limit={$memoryLimit}"];
    }
}

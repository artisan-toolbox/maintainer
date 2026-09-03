<?php

namespace App\Commands\CodeQuality;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Git\GitWorkingTree;
use App\Support\ProjectPath;
use App\Support\Quality\QualityCheckPrompt;
use App\Support\Quality\QualityCommand;
use App\Support\Quality\QualityCommandRunner;
use App\Support\Quality\QualityCommitPrompt;
use App\Support\Quality\QualityConfigurationManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\multiselect;

abstract class RunCodeQualityWorkflowCommand extends Command
{
    abstract protected function configurationKey(): string;

    abstract protected function workflowLabel(): string;

    protected function offersCommit(): bool
    {
        return false;
    }

    protected function offersCheck(): bool
    {
        return false;
    }

    /**
     * Execute the console command.
     */
    public function handle(
        ProjectPath $projectPath,
        MaintainerConfiguration $configuration,
        QualityConfigurationManager $qualityConfigurations,
        QualityCommandRunner $runner,
        GitWorkingTree $workingTree,
        QualityCheckPrompt $qualityCheckPrompt,
        QualityCommitPrompt $qualityCommitPrompt,
    ): int {
        $projectRoot = $projectPath->root();
        $interactive = $this->input->isInteractive();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $commands = $this->selectedCommands($configuration);
            $ran = 0;
            $skipped = 0;

            foreach ($commands as $command) {
                $availability = $command->availability($projectRoot);

                if (! $availability->available) {
                    $this->components->warn("Skipped {$command->label()}: {$availability->reason}.");
                    $skipped++;

                    continue;
                }

                $configurationPath = null;
                $tool = $command->configurationTool();

                if ($tool !== null) {
                    $configurationPath = $qualityConfigurations->find($tool, $projectRoot);

                    if ($configurationPath === null) {
                        $filenames = implode(', ', $tool->configurationFilenames());
                        $this->components->warn("Skipped {$command->label()}: no supported configuration file was found ({$filenames}).");
                        $skipped++;

                        continue;
                    }
                }

                $this->components->twoColumnDetail("Running {$command->label()}", $command->name());

                $exitCode = $runner->run(
                    $command->command($projectRoot, $configurationPath, $configuration),
                    $projectRoot,
                    function (string $output): void {
                        $this->output->write($output);
                    },
                );

                if ($exitCode !== self::SUCCESS) {
                    $this->components->error("{$command->label()} failed with exit code {$exitCode}.");

                    return $exitCode;
                }

                $ran++;
            }
        } catch (RuntimeException $exception) {
            $this->components->error("Unable to run {$this->workflowLabel()}: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->success("{$this->workflowLabel()} completed successfully: {$ran} run, {$skipped} skipped.");

        if ($this->offersCheck() && $interactive && $qualityCheckPrompt->shouldRun()) {
            $exitCode = $this->callNonInteractively('quality:check', $interactive);

            if ($exitCode !== self::SUCCESS) {
                return $exitCode;
            }
        }

        if ($ran > 0 && $this->offersCommit() && $interactive && $this->shouldCreateCommit($projectRoot, $workingTree, $qualityCommitPrompt)) {
            return $this->call('commit');
        }

        return self::SUCCESS;
    }

    private function callNonInteractively(string $command, bool $interactive): int
    {
        try {
            return $this->call($command, ['--no-interaction' => true]);
        } finally {
            $this->input->setInteractive($interactive);
            $this->configurePrompts($this->input);
        }
    }

    /**
     * @return list<QualityCommand>
     */
    private function selectedCommands(MaintainerConfiguration $configuration): array
    {
        $configured = $configuration->get($this->configurationKey());

        throw_unless(
            is_array($configured) && array_is_list($configured),
            RuntimeException::class,
            "{$this->configurationKey()} must be a list of quality command contracts.",
        );

        $commands = [];

        foreach ($configured as $class) {
            throw_unless(
                is_string($class) && (class_exists($class) || interface_exists($class)),
                RuntimeException::class,
                "Every {$this->configurationKey()} entry must be an existing quality command contract.",
            );

            $command = $this->getLaravel()->make($class);

            throw_unless(
                $command instanceof QualityCommand,
                RuntimeException::class,
                "{$class} must resolve to an implementation of ".QualityCommand::class.'.',
            );

            $commands[$class] = $command;
        }

        if ($commands === []) {
            return [];
        }

        $selected = $this->option('tool');

        if ($selected === [] && $this->input->isInteractive()) {
            $selected = multiselect(
                label: "Choose tools for {$this->workflowLabel()}",
                options: array_map(static fn (QualityCommand $command): string => $command->label(), $commands),
                default: array_keys($commands),
                scroll: count($commands),
                required: true,
            );
        }

        if ($selected === []) {
            return array_values($commands);
        }

        $byName = [];

        foreach ($commands as $class => $command) {
            $byName[$class] = $command;
            $byName[$command->name()] = $command;
        }

        $resolved = [];

        foreach ($selected as $value) {
            $command = is_string($value) ? ($byName[$value] ?? null) : null;

            throw_unless(
                $command instanceof QualityCommand,
                RuntimeException::class,
                'Every selected tool must be one of: '.implode(', ', array_map(
                    static fn (QualityCommand $item): string => $item->name(),
                    array_values($commands),
                )).'.',
            );

            $resolved[$command::class] = $command;
        }

        return array_values($resolved);
    }

    private function shouldCreateCommit(
        string $projectRoot,
        GitWorkingTree $workingTree,
        QualityCommitPrompt $prompt,
    ): bool {
        try {
            if ($workingTree->isClean($projectRoot)) {
                return false;
            }
        } catch (RuntimeException $exception) {
            $this->components->warn("Unable to inspect Git changes, so the commit suggestion was skipped: {$exception->getMessage()}");

            return false;
        }

        return $prompt->shouldCommit();
    }
}

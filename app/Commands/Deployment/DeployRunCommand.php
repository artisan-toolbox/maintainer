<?php

namespace App\Commands\Deployment;

use App\Support\Deployer\DeployerArguments;
use App\Support\Deployer\DeployerRunner;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

#[Signature('deploy:run {task? : Declared Deployer task to run} {selector?* : Host selectors and custom task options after --} {--file= : Alternative Deployer recipe path} {--tag= : Git tag to deploy} {--revision= : Git revision to deploy} {--branch= : Git branch to deploy} {--option=* : Override a Deployer configuration option} {--limit= : Maximum number of hosts running tasks in parallel} {--no-hooks : Run without Deployer hooks} {--plan : Display the task plan without executing it} {--start-from= : Start execution from a specific task} {--log= : Write the Deployer log to a file} {--profile= : Write the Deployer profile to a file}')]
#[Description('Choose and run a declared Deployer task')]
final class DeployRunCommand extends Command
{
    public function handle(ProjectPath $projectPath, DeployerRunner $runner): int
    {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $task = $this->argument('task');

        if (($task === null || $task === '') && ! $this->input->isInteractive()) {
            $this->components->error('Specify a Deployer task when running without interaction.');

            return self::FAILURE;
        }

        try {
            $discover = fn (): array => $runner->tasks($this->option('file'), is_string($task) && $task !== '');

            if ($this->input->isInteractive()) {
                $tasks = spin($discover, 'Discovering Deployer tasks');
            } else {
                $this->components->info('Discovering Deployer tasks.');
                $tasks = $discover();
            }

            throw_if($tasks === [], RuntimeException::class, 'The selected recipe declares no available Deployer tasks.');

            if ($task === null || $task === '') {
                $choices = [];

                foreach ($tasks as $definition) {
                    $choices["task:{$definition->name}"] = $definition->description === '' ? $definition->name : "{$definition->name} - {$definition->description}";
                }

                $selection = select('Which Deployer task should run?', $choices);
                throw_unless(is_string($selection), RuntimeException::class, 'The selected Deployer task is invalid.');
                $task = substr($selection, strlen('task:'));
            }

            throw_unless(isset($tasks[$task]), RuntimeException::class, 'The requested task is not declared by the selected recipe.');
            $tokens = $tasks[$task]->arguments(array_values($this->argument('selector')));
            $arguments = DeployerArguments::build($task, $this->options(), $tokens['selectors'], $this->input->isInteractive(), $tokens['options']);

            $this->components->twoColumnDetail('Running Deployer task', $task);
            $exitCode = $runner->run($arguments, function (string $output): void {
                $this->output->write($output);
            }, $this->input->isInteractive());
        } catch (RuntimeException $exception) {
            $this->components->error("Unable to run Deployer: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->components->error("Deployer task failed with exit code {$exitCode}.");

            return $exitCode;
        }

        $this->components->success('Deployer task completed successfully.');

        return self::SUCCESS;
    }
}

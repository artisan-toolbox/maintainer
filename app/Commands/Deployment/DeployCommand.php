<?php

namespace App\Commands\Deployment;

use App\Support\Deployer\DeployerArguments;
use App\Support\Deployer\DeployerRunner;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

#[Signature('deploy {selector?* : Host selectors passed to Deployer} {--file= : Alternative Deployer recipe path} {--tag= : Git tag to deploy} {--revision= : Git revision to deploy} {--branch= : Git branch to deploy} {--option=* : Override a Deployer configuration option} {--limit= : Maximum number of hosts running tasks in parallel} {--no-hooks : Run without Deployer hooks} {--plan : Display the deployment plan without executing it} {--start-from= : Start execution from a specific task} {--log= : Write the Deployer log to a file} {--profile= : Write the Deployer profile to a file}')]
#[Description('Deploy the project with Deployer')]
final class DeployCommand extends Command
{
    /**
     * Execute the deployment through the consuming project's Deployer binary.
     */
    public function handle(ProjectPath $projectPath, DeployerRunner $runner): int
    {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $this->components->twoColumnDetail('Running Deployer', $projectRoot);
            $exitCode = $runner->run(
                $this->deployerArguments(),
                function (string $output): void {
                    $this->output->write($output);
                },
                $this->input->isInteractive(),
            );
        } catch (RuntimeException $exception) {
            $this->components->error("Unable to run Deployer: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->components->error("Deployer failed with exit code {$exitCode}.");

            return $exitCode;
        }

        $this->components->success('Deployer completed successfully.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function deployerArguments(): array
    {
        return DeployerArguments::build('deploy', $this->options(), array_values($this->argument('selector')), $this->input->isInteractive());
    }
}

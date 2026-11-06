<?php

namespace App\Commands\Deployment;

use App\Support\Deployer\DeployerArguments;
use App\Support\Deployer\DeployerRunner;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;

#[Signature('deploy:unlock {selector?* : Host selectors passed to Deployer} {--file= : Alternative Deployer recipe path} {--option=* : Override a Deployer configuration option} {--limit= : Maximum number of hosts running tasks in parallel} {--no-hooks : Run without Deployer hooks} {--plan : Display the unlock plan without executing it} {--log= : Write the Deployer log to a file} {--profile= : Write the Deployer profile to a file}')]
#[Description('Unlock a failed Deployer deployment')]
final class DeployUnlockCommand extends Command
{
    /**
     * Unlock the consuming project through its Deployer binary.
     */
    public function handle(ProjectPath $projectPath, DeployerRunner $runner): int
    {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        try {
            $this->components->twoColumnDetail('Unlocking Deployer', $projectRoot);
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
            $this->components->error("Deployer unlock failed with exit code {$exitCode}.");

            return $exitCode;
        }

        $this->components->success('Deployer deployment unlocked successfully.');

        if (! $this->input->isInteractive() || $this->option('plan') === true || ! confirm('Run deployment again with the same options and selectors?', default: false)) {
            return self::SUCCESS;
        }

        $options = ['selector' => $this->argument('selector')];

        foreach (['file', 'option', 'limit', 'no-hooks', 'log', 'profile'] as $name) {
            $options["--{$name}"] = $this->option($name);
        }

        return $this->call('deploy', $options);
    }

    /**
     * @return list<string>
     */
    private function deployerArguments(): array
    {
        return DeployerArguments::build('deploy:unlock', $this->options(), array_values($this->argument('selector')), $this->input->isInteractive());
    }
}

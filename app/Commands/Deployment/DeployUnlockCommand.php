<?php

namespace App\Commands\Deployment;

use App\Support\Deployer\DeployerRunner;
use App\Support\ProjectPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

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
                $projectRoot,
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

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function deployerArguments(): array
    {
        $arguments = [];
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            $arguments[] = "--file={$file}";
        }

        $arguments[] = 'deploy:unlock';

        foreach (['limit', 'log', 'profile'] as $name) {
            $value = $this->option($name);

            if (is_string($value) && $value !== '') {
                $arguments[] = "--{$name}={$value}";
            }
        }

        $overrides = $this->option('option');

        foreach ($overrides as $override) {
            throw_if($override === null || $override === '', RuntimeException::class, 'A Deployer configuration override is invalid.');

            $arguments[] = "--option={$override}";
        }

        foreach (['no-hooks', 'plan'] as $name) {
            if ($this->option($name) === true) {
                $arguments[] = "--{$name}";
            }
        }

        if (! $this->input->isInteractive()) {
            $arguments[] = '--no-interaction';
        }

        $selectors = $this->argument('selector');

        foreach ($selectors as $selector) {
            $arguments[] = $selector;
        }

        return $arguments;
    }
}

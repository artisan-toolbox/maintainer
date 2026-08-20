<?php

namespace App\Support\Deployer;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class DeployerRunner
{
    public function __construct(
        private TemporarySshIdentityFile $sshIdentity,
    ) {}

    /**
     * Run the Deployer binary installed by the consuming project.
     *
     * @param  list<string>  $arguments
     * @param  Closure(string): void  $output
     *
     * @throws \Throwable
     */
    public function run(
        array $arguments,
        Closure $output,
        bool $interactive,
    ): int {
        $projectRoot = project_path();
        $binary = project_path('vendor/bin/dep');

        if (PHP_OS_FAMILY === 'Windows' && is_file($binary.'.bat')) {
            $binary .= '.bat';
        }

        throw_unless(
            is_file($binary),
            RuntimeException::class,
            'Deployer is not installed in the project. Install artisan-toolbox/maintainer or deployer/deployer as a Composer development dependency first.',
        );

        return $this->sshIdentity->using(function (?string $identityFile) use ($binary, $arguments, $projectRoot, $interactive, $output): int {
            $environment = [
                'MAINTAINER_CONTRIB' => project_path('vendor/artisan-toolbox/maintainer/app/Deployer/contrib.php'),
                'MAINTAINER_SSH_IDENTITY_FILE' => false,
            ];

            if ($identityFile !== null) {
                $environment['MAINTAINER_SSH_IDENTITY_FILE'] = $identityFile;
            }

            return $this->runProcess(
                $binary,
                $arguments,
                $projectRoot,
                $environment,
                $output,
                $interactive,
            );
        });
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     * @param  Closure(string): void  $output
     */
    private function runProcess(
        string $binary,
        array $arguments,
        string $projectRoot,
        array $environment,
        Closure $output,
        bool $interactive,
    ): int {
        $process = new Process(
            [$binary, ...$arguments],
            $projectRoot,
            $environment,
        );
        $process->setTimeout(null);

        if ($interactive && Process::isTtySupported()) {
            $process->setTty(true);

            return $process->run();
        }

        if ($interactive && defined('STDIN')) {
            $process->setInput(STDIN);
        }

        return $process->run(static function (string $type, string $buffer) use ($output): void {
            $output($buffer);
        });
    }
}

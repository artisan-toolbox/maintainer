<?php

namespace App\Support\Deployer;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class DeployerRunner
{
    /**
     * Run the Deployer binary installed by the consuming project.
     *
     * @param  list<string>  $arguments
     * @param  Closure(string): void  $output
     */
    public function run(
        string $projectRoot,
        array $arguments,
        Closure $output,
        bool $interactive,
    ): int {
        $binary = $projectRoot
            .DIRECTORY_SEPARATOR.'vendor'
            .DIRECTORY_SEPARATOR.'bin'
            .DIRECTORY_SEPARATOR.'dep';

        if (PHP_OS_FAMILY === 'Windows' && is_file($binary.'.bat')) {
            $binary .= '.bat';
        }

        throw_unless(
            is_file($binary),
            RuntimeException::class,
            'Deployer is not installed in the project. Install artisan-toolbox/maintainer or deployer/deployer as a Composer development dependency first.',
        );

        $process = new Process(
            [$binary, ...$arguments],
            $projectRoot,
            [
                'MAINTAINER_TASKS_PATH' => $projectRoot
                    .DIRECTORY_SEPARATOR.'vendor'
                    .DIRECTORY_SEPARATOR.'artisan-toolbox'
                    .DIRECTORY_SEPARATOR.'maintainer'
                    .DIRECTORY_SEPARATOR.'app'
                    .DIRECTORY_SEPARATOR.'Deployer'
                    .DIRECTORY_SEPARATOR.'tasks.php',
            ],
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

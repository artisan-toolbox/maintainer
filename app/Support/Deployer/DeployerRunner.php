<?php

namespace App\Support\Deployer;

use Closure;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

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
     * @param  (Closure(string): void)|null  $errorOutput
     *
     * @throws Throwable
     */
    public function run(
        array $arguments,
        Closure $output,
        bool $interactive,
        ?Closure $errorOutput = null,
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

        return $this->sshIdentity->using(function (?string $identityFile) use ($binary, $arguments, $projectRoot, $interactive, $output, $errorOutput): int {
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
                $errorOutput,
            );
        });
    }

    /**
     * Discover the recipe tasks exposed by Deployer's task command definition.
     *
     * @return array<string, DeployerTask>
     */
    public function tasks(?string $file = null, bool $includeHidden = false): array
    {
        $arguments = $file === null || $file === '' ? [] : ["--file={$file}"];
        array_push($arguments, 'list', '--format=json', '--no-ansi', '--no-interaction');
        $json = '';
        $diagnostics = '';
        $exitCode = $this->run($arguments, static function (string $output) use (&$json): void {
            $json .= $output;
        }, false, static function (string $output) use (&$diagnostics): void {
            $diagnostics .= $output;
        });

        throw_if($exitCode !== 0, RuntimeException::class, "Deployer task discovery failed with exit code {$exitCode}. ".trim($diagnostics));

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Deployer returned an invalid JSON task list.', previous: $exception);
        }

        throw_unless(is_array($data) && isset($data['commands']) && is_array($data['commands']) && array_is_list($data['commands']), RuntimeException::class, 'Deployer returned an invalid task list.');

        $tasks = [];

        foreach ($data['commands'] as $command) {
            throw_unless(is_array($command) && is_string($command['name'] ?? null) && is_string($command['description'] ?? null) && is_bool($command['hidden'] ?? null) && is_array($command['definition'] ?? null), RuntimeException::class, 'Deployer returned an invalid task definition.');

            if ($command['name'] === 'worker' || ($command['hidden'] && ! $includeHidden)) {
                continue;
            }

            $definition = $command['definition'];
            $options = $definition['options'] ?? null;
            $arguments = $definition['arguments'] ?? null;
            throw_unless(is_array($options) && is_array($arguments), RuntimeException::class, 'Deployer returned an invalid task definition.');

            if (! isset($arguments['selector']) || array_diff(['option', 'limit', 'no-hooks', 'plan', 'start-from', 'log', 'profile'], array_keys($options)) !== []) {
                continue;
            }

            $name = $command['name'];
            throw_unless($name !== '' && ! str_starts_with($name, '-') && ! str_contains($name, "\0") && ! isset($tasks[$name]), RuntimeException::class, 'Deployer returned an invalid task name.');
            $taskOptions = [];

            foreach ($options as $optionName => $option) {
                throw_unless(is_string($optionName) && is_array($option) && is_bool($option['accept_value'] ?? null) && is_bool($option['is_value_required'] ?? null) && is_bool($option['is_multiple'] ?? null), RuntimeException::class, 'Deployer returned an invalid task option.');
                $taskOptions[$optionName] = [
                    'accept_value' => $option['accept_value'],
                    'is_value_required' => $option['is_value_required'],
                    'is_multiple' => $option['is_multiple'],
                ];
            }

            $tasks[$name] = new DeployerTask($name, $command['description'], $taskOptions);
        }

        ksort($tasks);

        return $tasks;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     * @param  Closure(string): void  $output
     * @param  (Closure(string): void)|null  $errorOutput
     */
    private function runProcess(
        string $binary,
        array $arguments,
        string $projectRoot,
        array $environment,
        Closure $output,
        bool $interactive,
        ?Closure $errorOutput,
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

        $stdinBlocked = null;

        if ($interactive && defined('STDIN')) {
            $stdinBlocked = stream_get_meta_data(STDIN)['blocked'];
            $process->setInput(STDIN);
        }

        try {
            return $process->run(static function (string $type, string $buffer) use ($output, $errorOutput): void {
                static $outputFailed = false;

                if ($outputFailed) {
                    return;
                }

                try {
                    if ($type === Process::ERR && $errorOutput !== null) {
                        $errorOutput($buffer);
                    } else {
                        $output($buffer);
                    }
                } catch (Throwable $exception) {
                    $outputFailed = true;

                    throw $exception;
                }
            });
        } finally {
            if ($stdinBlocked !== null) {
                $process->stop(0);
                stream_set_blocking(STDIN, $stdinBlocked);
            }
        }
    }
}

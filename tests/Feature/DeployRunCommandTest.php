<?php

use App\Support\Deployer\DeployerRunner;
use App\Support\Ssh\Ed25519KeyGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\SelectPrompt;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

beforeEach(function () {
    ConfirmPrompt::fallbackWhen(true);
    SelectPrompt::fallbackWhen(true);
});

function deployerTaskFixture(string $name, string $description = '', bool $hidden = false): array
{
    $options = [];

    foreach (['option', 'limit', 'no-hooks', 'plan', 'start-from', 'log', 'profile', 'message', 'force', 'item'] as $option) {
        $options[$option] = [
            'accept_value' => ! in_array($option, ['no-hooks', 'plan', 'force'], true),
            'is_value_required' => ! in_array($option, ['no-hooks', 'plan', 'force'], true),
            'is_multiple' => in_array($option, ['option', 'item'], true),
        ];
    }

    return [
        'name' => $name,
        'description' => $description,
        'hidden' => $hidden,
        'definition' => ['arguments' => ['selector' => ['is_array' => true]], 'options' => $options],
    ];
}

function installTaskDeployerBinary(string $directory, Filesystem $files, ?string $catalog = null, int $discoveryExit = 0, int $taskExit = 0, ?int $retryExit = null, string $discoveryError = ''): void
{
    $catalog ??= json_encode(['commands' => [
        deployerTaskFixture('server:apt:update', 'Refresh package lists'),
        deployerTaskFixture('deploy'),
        deployerTaskFixture('secret', hidden: true),
        deployerTaskFixture('worker', hidden: true),
        ['name' => 'run', 'description' => 'Run a shell command', 'hidden' => false, 'definition' => ['arguments' => ['selector' => []], 'options' => []]],
    ]], JSON_THROW_ON_ERROR);

    $script = <<<'SCRIPT'
<?php
$arguments = array_slice($argv, 1);
file_put_contents(__DIR__.'/../../invocations.jsonl', json_encode($arguments)."\n", FILE_APPEND);
$identity = getenv('MAINTAINER_SSH_IDENTITY_FILE');
file_put_contents(__DIR__.'/../../identity-paths.jsonl', json_encode($identity)."\n", FILE_APPEND);
if (in_array('list', $arguments, true)) {
    fwrite(STDERR, DISCOVERY_ERROR);
    echo CATALOG;
    exit(DISCOVERY_EXIT);
}
echo "Deployer output\n";
exit(in_array('deploy', $arguments, true) ? RETRY_EXIT : TASK_EXIT);
SCRIPT;
    $script = str_replace(['CATALOG', 'DISCOVERY_EXIT', 'TASK_EXIT', 'RETRY_EXIT', 'DISCOVERY_ERROR'], [var_export($catalog, true), (string) $discoveryExit, (string) $taskExit, (string) ($retryExit ?? $taskExit), var_export($discoveryError, true)], $script);
    $path = $directory.'/vendor/bin/dep';

    if (PHP_OS_FAMILY === 'Windows') {
        $files->put($path.'.php', $script);
        $files->put($path, "<?php require __DIR__.'/dep.php';\n");
        $files->put($path.'.bat', '@"'.PHP_BINARY.'" "'.$path.'.php" %*'."\r\n");
    } else {
        $files->put($path.'.php', $script);
        $files->put($path, "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($path.'.php').' "$@"'."\n");
        chmod($path, 0755);
    }
}

function taskDeployerInvocations(string $directory, Filesystem $files): array
{
    return array_map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $files->lines($directory.'/invocations.jsonl')->filter(fn (string $line): bool => $line !== '')->values()->all());
}

it('registers the declared task runner', function () {
    expect($this->app->make(Kernel::class)->all())->toHaveKey('deploy:run');
});

it('parses the task catalog independently of recipe stderr diagnostics', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files, discoveryError: "Recipe loaded successfully\n");
        $this->artisan('deploy:run', ['task' => 'deploy', '--no-interaction' => true])
            ->assertSuccessful();
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(2);
    });
});

it('includes recipe stderr when task discovery fails', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files, discoveryExit: 42, discoveryError: "Recipe configuration is invalid\n");
        $this->artisan('deploy:run', ['task' => 'deploy', '--no-interaction' => true])
            ->expectsOutputToContain('Recipe configuration is invalid')
            ->assertFailed();
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(1);
    });
});

it('discovers visible recipe tasks and excludes hidden and built-in commands', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);

        $this->artisan('deploy:run', ['--file' => 'ops/deploy.php', '--plan' => true])
            ->expectsChoice('Which Deployer task should run?', 'task:server:apt:update', [
                'task:deploy' => 'deploy',
                'task:server:apt:update' => 'server:apt:update - Refresh package lists',
            ])
            ->expectsOutputToContain('Deployer task completed successfully.')
            ->assertSuccessful();

        expect(taskDeployerInvocations($directory, $files))->toBe([
            ['--file=ops/deploy.php', 'list', '--format=json', '--no-ansi', '--no-interaction'],
            ['--file=ops/deploy.php', 'server:apt:update', '--plan'],
        ]);
    });
});

it('runs an explicit task noninteractively with options and selectors as separate arguments', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $message = 'hello; touch '.$directory.'/injected';

        $this->artisan('deploy:run', [
            'task' => 'server:apt:update',
            'selector' => ['production', '--message='.$message, '--force', '--item=first', '--item=second'],
            '--file' => 'recipe with spaces.php',
            '--option' => ['keep_releases=3'],
            '--limit' => '2',
            '--no-interaction' => true,
        ])->assertSuccessful();

        expect(taskDeployerInvocations($directory, $files)[1])->toBe([
            '--file=recipe with spaces.php', 'server:apt:update', '--limit=2', '--option=keep_releases=3', '--no-interaction',
            '--message='.$message, '--force', '--item=first', '--item=second', '--', 'production',
        ])->and($files->exists($directory.'/injected'))->toBeFalse();
    });
});

it('preserves the standard input blocking mode after interactive deployments', function (bool $blocked, int $exitCode) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($blocked, $exitCode) {
        installTaskDeployerBinary($directory, $files, taskExit: $exitCode);
        $files->put($directory.'/runner.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
$modeSupported = stream_set_blocking(STDIN, $argv[2] === '1');
$initialBlocked = stream_get_meta_data(STDIN)['blocked'];
$output = '';
$exitCode = $app->make(\App\Support\Deployer\DeployerRunner::class)->run(['deploy'], static function (string $buffer) use (&$output): void {
    $output .= $buffer;
}, true);
echo json_encode([
    'exitCode' => $exitCode,
    'output' => $output,
    'modeSupported' => $modeSupported,
    'initialBlocked' => $initialBlocked,
    'blocked' => stream_get_meta_data(STDIN)['blocked'],
], JSON_THROW_ON_ERROR);
PHP);
        $process = new Process([PHP_BINARY, $directory.'/runner.php', dirname(__DIR__, 2), $blocked ? '1' : '0'], $directory, timeout: 5);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['exitCode'])->toBe($exitCode)
            ->and($result['output'])->toContain('Deployer output')
            ->and($result['blocked'])->toBe($result['initialBlocked'])
            ->and($result['modeSupported'])->toBeBool();

        if ($result['modeSupported']) {
            expect($result['initialBlocked'])->toBe($blocked);
        } else {
            expect($result['initialBlocked'])->toBeTrue();
        }
    });
})->with([true, false])->with([0, 42]);

it('preserves the standard input blocking mode when deployment output handling throws', function (bool $blocked) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($blocked) {
        installTaskDeployerBinary($directory, $files);
        $files->put($directory.'/runner.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
$modeSupported = stream_set_blocking(STDIN, $argv[2] === '1');
$initialBlocked = stream_get_meta_data(STDIN)['blocked'];
$exception = null;
try {
    $app->make(\App\Support\Deployer\DeployerRunner::class)->run(['deploy'], static function (): void {
        throw new \RuntimeException('Deployment output handling failed.');
    }, true);
} catch (\RuntimeException $caught) {
    $exception = ['class' => $caught::class, 'message' => $caught->getMessage()];
}
echo json_encode([
    'exception' => $exception,
    'modeSupported' => $modeSupported,
    'initialBlocked' => $initialBlocked,
    'blocked' => stream_get_meta_data(STDIN)['blocked'],
], JSON_THROW_ON_ERROR);
PHP);
        $process = new Process([PHP_BINARY, $directory.'/runner.php', dirname(__DIR__, 2), $blocked ? '1' : '0'], $directory, timeout: 5);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['exception'])->toBe([
            'class' => RuntimeException::class,
            'message' => 'Deployment output handling failed.',
        ])->and($result['blocked'])->toBe($result['initialBlocked'])
            ->and($result['modeSupported'])->toBeBool();

        if ($result['modeSupported']) {
            expect($result['initialBlocked'])->toBe($blocked);
        } else {
            expect($result['initialBlocked'])->toBeTrue();
        }
    });
})->with([true, false]);

it('completes an interactive deployment while standard input remains open', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $files->put($directory.'/runner.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
$exitCode = $app->make(\App\Support\Deployer\DeployerRunner::class)->run(['deploy'], static function (string $buffer): void {
    echo $buffer;
}, true);
echo "Deployment completed.\n";
exit($exitCode);
PHP);
        $input = new InputStream;
        $process = new Process([PHP_BINARY, $directory.'/runner.php', dirname(__DIR__, 2)], $directory, input: $input, timeout: 5);

        try {
            $process->run();

            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
                ->and($process->getOutput())->toContain("Deployer output\n", "Deployment completed.\n")
                ->and($input->isClosed())->toBeFalse();
        } finally {
            $input->close();
        }
    });
});

it('forwards delayed interactive input and preserves deployment arguments', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $files->put($directory.'/vendor/bin/dep.php', <<<'PHP'
<?php
echo "Waiting for deployment input.\n";
echo json_encode([
    'arguments' => array_slice($argv, 1),
    'input' => fgets(STDIN),
], JSON_THROW_ON_ERROR)."\n";
PHP);
        $files->put($directory.'/runner.php', <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
$exitCode = $app->make(\App\Support\Deployer\DeployerRunner::class)->run(['deploy', $argv[2]], static function (string $buffer): void {
    echo $buffer;
}, true);
echo "Deployment completed.\n";
exit($exitCode);
PHP);
        $argument = '--message=deploy with spaces; "quoted" \\path';
        $answer = "approve with spaces; \"quoted\" \\path\n";
        $input = new InputStream;
        $process = new Process([PHP_BINARY, $directory.'/runner.php', dirname(__DIR__, 2), $argument], $directory, input: $input, timeout: 5);
        $output = '';
        $answerSent = false;

        try {
            $process->run(static function (string $type, string $buffer) use ($input, $answer, &$output, &$answerSent): void {
                if ($type !== Process::OUT) {
                    return;
                }

                $output .= $buffer;

                if (! $answerSent && str_contains($output, "Waiting for deployment input.\n")) {
                    $input->write($answer);
                    $answerSent = true;
                }
            });

            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
                ->and($answerSent)->toBeTrue()
                ->and($process->getOutput())->toContain(
                    json_encode(['arguments' => ['deploy', $argument], 'input' => $answer], JSON_THROW_ON_ERROR)."\n",
                    "Deployment completed.\n",
                )
                ->and($input->isClosed())->toBeFalse();
        } finally {
            $input->close();
        }
    });
});

it('finishes an interactive CLI deployment while its input pipe remains open', function (string $entrypoint) {
    $maintainer = dirname(__DIR__, 2).'/'.$entrypoint;

    withinTemporaryProject(function (string $directory, Filesystem $files) use ($maintainer) {
        installTaskDeployerBinary($directory, $files);
        $input = new InputStream;
        $process = new Process([PHP_BINARY, $maintainer, 'deploy:run', 'deploy'], $directory, input: $input, timeout: 5);

        try {
            $process->mustRun();

            expect($process->getOutput())->toContain('Deployer output', 'Deployer task completed successfully.')
                ->and(taskDeployerInvocations($directory, $files)[1])->toBe(['deploy'])
                ->and($input->isClosed())->toBeFalse();
        } finally {
            $input->close();
            $process->stop(0);
        }
    });
})->with(['source' => 'maintainer', 'distributed PHAR' => 'builds/maintainer']);

it('finishes process cleanup after output handling throws with standard input still open', function (bool $pendingOutput) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($pendingOutput) {
        installTaskDeployerBinary($directory, $files);

        if ($pendingOutput) {
            $files->put($directory.'/vendor/bin/dep.php', <<<'PHP'
<?php
echo "Deployer output\n";
$deadline = hrtime(true) + 2_000_000_000;
while (!file_exists(__DIR__.'/../../callback-started')) {
    if (hrtime(true) >= $deadline) {
        fwrite(STDERR, 'Timed out waiting for the deployment output callback to start.');
        exit(3);
    }
    usleep(1000);
}
echo str_repeat('x', 1024);
file_put_contents(__DIR__.'/../../output-staged', 'ready');
echo str_repeat('x', 131072);
PHP);
        }

        $script = <<<'PHP'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
stream_set_blocking(STDIN, true);
$callbackCalls = 0;
try {
    $app->make(\App\Support\Deployer\DeployerRunner::class)->run(['deploy'], static function () use (&$callbackCalls, $argv): void {
        $callbackCalls++;
        gc_collect_cycles();
        if ($argv[2] === 'pending-output') {
            file_put_contents(getcwd().'/callback-started', 'ready');
            $deadline = hrtime(true) + 2_000_000_000;
            while (!file_exists(getcwd().'/output-staged')) {
                if (hrtime(true) >= $deadline) {
                    throw new \RuntimeException('Timed out waiting for pending deployment output.');
                }
                usleep(1000);
            }
        }
        throw new \RuntimeException('Deployment output handling failed.');
    }, true);
    exit(1);
} catch (\RuntimeException $exception) {
    if ($exception->getMessage() !== 'Deployment output handling failed.') {
        throw $exception;
    }
}
unset($exception);
gc_collect_cycles();
if ($callbackCalls !== 1 || !stream_get_meta_data(STDIN)['blocked']) {
    fwrite(STDERR, 'Output callbacks must stop after failure and standard input must remain blocking.');
    exit(2);
}
echo "Process cleanup completed.\n";
PHP;
        $files->put($directory.'/runner.php', $script);
        $input = new InputStream;
        $process = new Process([PHP_BINARY, $directory.'/runner.php', dirname(__DIR__, 2), $pendingOutput ? 'pending-output' : 'single-output'], $directory, input: $input, timeout: 5);

        try {
            $process->run();

            expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
                ->and($process->getOutput())->toContain('Process cleanup completed.');
        } finally {
            $input->close();
        }
    });
})->with(['single output' => false, 'pending output' => true]);

it('requires an explicit task without interaction before starting discovery', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan('deploy:run', ['--no-interaction' => true])
            ->expectsOutputToContain('Specify a Deployer task')
            ->assertFailed();
        expect($files->exists($directory.'/invocations.jsonl'))->toBeFalse();
    });
});

it('refuses undeclared built-in and option-shaped task names', function (string $task) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($task) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan('deploy:run', ['task' => $task, '--no-interaction' => true])->assertFailed();
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(1);
    });
})->with(['missing', 'worker', 'run', '--file=evil.php', 'deploy; touch injected']);

it('rejects custom option smuggling and invalid custom values before executing tasks', function (array $tokens) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($tokens) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan('deploy:run', ['task' => 'deploy', 'selector' => $tokens, '--no-interaction' => true])->assertFailed();
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(1);
    });
})->with([
    [['--file=evil.php']], [['--file', 'evil.php']], [['--option=repository=evil']], [['--plan']], [['--no-interaction']], [['--help']], [['-f', 'evil.php']],
    [['--message', 'value']], [['--force=yes']], [['--unknown=x']], [['--message=a', '--message=b']],
]);

it('rejects invalid task catalogs and discovery failures without executing a task', function (string $catalog, int $exit) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($catalog, $exit) {
        installTaskDeployerBinary($directory, $files, $catalog, $exit);
        $this->artisan('deploy:run', ['task' => 'deploy', '--no-interaction' => true])->assertFailed();
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(1);
    });
})->with([
    ['not json', 0], ['{}', 0], ['{"commands":{}}', 0], ['{"commands":[]}', 0], ['{"commands":[{}]}', 0], ['{"commands":[]}', 42],
    [json_encode(['commands' => [deployerTaskFixture('--file=evil.php')]], JSON_THROW_ON_ERROR), 0],
]);

it('returns the task execution exit code', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files, taskExit: 42);
        $this->artisan('deploy:run', ['task' => 'deploy', '--no-interaction' => true])
            ->expectsOutputToContain('Deployer task failed with exit code 42.')
            ->assertExitCode(42);
    });
});

it('offers deployment again after a successful unlock with all the same options', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan('deploy:unlock', [
            'selector' => ['production', 'role=web'], '--file' => 'ops.php', '--option' => ['keep_releases=5'],
            '--limit' => '1', '--no-hooks' => true, '--log' => 'unlock.log', '--profile' => 'unlock.csv',
        ])->expectsConfirmation('Run deployment again with the same options and selectors?', 'yes')->assertSuccessful();

        expect(taskDeployerInvocations($directory, $files))->toBe([
            ['--file=ops.php', 'deploy:unlock', '--limit=1', '--log=unlock.log', '--profile=unlock.csv', '--option=keep_releases=5', '--no-hooks', '--', 'production', 'role=web'],
            ['--file=ops.php', 'deploy', '--limit=1', '--log=unlock.log', '--profile=unlock.csv', '--option=keep_releases=5', '--no-hooks', '--', 'production', 'role=web'],
        ]);
    });
});

it('does not deploy again when the unlock follow-up is declined', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan('deploy:unlock')->expectsConfirmation('Run deployment again with the same options and selectors?', 'no')->assertSuccessful();
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(1);
    });
});

it('never offers to retry an unlock plan a failed unlock or a noninteractive unlock', function (array $options, int $exit) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($options, $exit) {
        installTaskDeployerBinary($directory, $files, taskExit: $exit);
        $this->artisan('deploy:unlock', $options)->assertExitCode($exit);
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(1);
    });
})->with([[['--plan' => true], 0], [[], 42], [['--no-interaction' => true], 0]]);

it('rejects option-shaped host selectors in existing deployment commands', function (string $command) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($command) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan($command, ['selector' => ['--file=evil.php'], '--no-interaction' => true])->assertFailed();
        expect($files->exists($directory.'/invocations.jsonl'))->toBeFalse();
    });
})->with(['deploy', 'deploy:unlock']);

it('cleans temporary SSH identities after discovery errors and task failures', function (bool $discoveryFailure) {
    forgetTestEnvironmentVariable('APP_KEY');

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) use ($discoveryFailure) {
            $files->put($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
            $key = resolve(Ed25519KeyGenerator::class)->generatePrivateKey('owner@example.com');
            putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', ['ssh_key' => Crypt::encryptString($key)]);
            installTaskDeployerBinary($directory, $files, $discoveryFailure ? 'bad json' : null, taskExit: 42);
            $this->artisan('deploy:run', ['task' => 'deploy', '--no-interaction' => true])->assertExitCode($discoveryFailure ? 1 : 42);

            foreach ($files->lines($directory.'/identity-paths.jsonl')->filter(fn (string $line): bool => $line !== '') as $line) {
                $path = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                expect($path)->toBeString()->not->toBe('')->and($files->exists($path))->toBeFalse();
            }
        });
    } finally {
        forgetTestEnvironmentVariable('APP_KEY');
    }
})->with([true, false]);

it('discovers real recipe tasks without running their bodies or connecting to hosts', function () {
    $deployer = dirname(__DIR__, 2).'/vendor/deployer/deployer/bin/dep';

    withinTemporaryProject(function (string $directory, Filesystem $files) use ($deployer) {
        $path = $directory.'/vendor/bin/dep';
        $source = '<?php require '.var_export($deployer, true).';';

        if (PHP_OS_FAMILY === 'Windows') {
            $files->put($path.'.php', $source);
            $files->put($path.'.bat', '@"'.PHP_BINARY.'" "'.$path.'.php" %*'."\r\n");
        } else {
            $files->put($path.'.php', $source);
            $files->put($path, "#!/bin/sh\nexec ".escapeshellarg(PHP_BINARY).' '.escapeshellarg($path.'.php').' "$@"'."\n");
            chmod($path, 0755);
        }

        $files->put($directory.'/custom.php', <<<'RECIPE'
<?php
namespace Deployer;
localhost('local');
desc('Visible local task');
task('sample:visible', function () { throw new \RuntimeException('Task body must not run during discovery.'); });
task('sample:hidden', function () {})->hidden();
RECIPE);
        $tasks = resolve(DeployerRunner::class)->tasks('custom.php');
        expect(array_keys($tasks))->toBe(['sample:visible'])->and($tasks['sample:visible']->description)->toBe('Visible local task');
    });
});

it('accepts passthrough task options through the actual command line', function () {
    $maintainer = dirname(__DIR__, 2).'/maintainer';

    withinTemporaryProject(function (string $directory, Filesystem $files) use ($maintainer) {
        installTaskDeployerBinary($directory, $files);
        $process = new Process([PHP_BINARY, $maintainer, 'deploy:run', 'deploy', 'production', '-n', '--', '--message=hello world', '--force'], $directory);
        $process->run();
        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect(taskDeployerInvocations($directory, $files)[1])->toBe(['deploy', '--no-interaction', '--message=hello world', '--force', '--', 'production']);
    });
});

it('runs explicitly named hidden recipe tasks without exposing internal commands', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files);
        $this->artisan('deploy:run', ['task' => 'secret', '--no-interaction' => true])->assertSuccessful();
        expect(taskDeployerInvocations($directory, $files)[1])->toBe(['secret', '--no-interaction']);
    });
});

it('returns a failed deployment status after the unlock follow-up', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files, retryExit: 42);
        $this->artisan('deploy:unlock')
            ->expectsConfirmation('Run deployment again with the same options and selectors?', 'yes')
            ->expectsOutputToContain('Deployer failed with exit code 42.')
            ->assertExitCode(42);
        expect(taskDeployerInvocations($directory, $files))->toHaveCount(2);
    });
});

it('keeps shell punctuation in declared task names as a single process argument', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $name = 'custom;touch '.$directory.'/injected';
        installTaskDeployerBinary($directory, $files, json_encode(['commands' => [deployerTaskFixture($name)]], JSON_THROW_ON_ERROR));
        $this->artisan('deploy:run', ['task' => $name, '--no-interaction' => true])->assertSuccessful();
        expect(taskDeployerInvocations($directory, $files)[1])->toBe([$name, '--no-interaction'])
            ->and($files->exists($directory.'/injected'))->toBeFalse();
    });
});

it('selects numeric task names without confusing option keys with labels', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installTaskDeployerBinary($directory, $files, json_encode(['commands' => [deployerTaskFixture('0', 'Numeric task')]], JSON_THROW_ON_ERROR));
        $this->artisan('deploy:run')
            ->expectsChoice('Which Deployer task should run?', 'task:0', ['task:0' => '0 - Numeric task'])
            ->assertSuccessful();
        expect(taskDeployerInvocations($directory, $files)[1])->toBe(['0']);
    });
});

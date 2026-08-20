<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

function installFakeDeployerBinary(string $directory, Filesystem $files, int $exitCode = 0): void
{
    $windows = PHP_OS_FAMILY === 'Windows';
    $path = $directory.'/vendor/bin/dep'.($windows ? '.bat' : '');
    $log = $directory.'/deployer.log';
    $tasksPathLog = $directory.'/maintainer-tasks-path.log';
    $script = $windows
        ? "@echo off\r\necho %*>\"{$log}\"\r\necho %MAINTAINER_TASKS_PATH%>\"{$tasksPathLog}\"\r\necho Deployer output\r\nexit /b {$exitCode}\r\n"
        : "#!/bin/sh\nprintf '%s\\n' \"\$*\" > ".escapeshellarg($log)."\nprintf '%s\\n' \"\$MAINTAINER_TASKS_PATH\" > ".escapeshellarg($tasksPathLog)."\nprintf 'Deployer output\\n'\nexit {$exitCode}\n";

    $files->ensureDirectoryExists(dirname($path));
    $files->put($path, $script);
    chmod($path, 0755);
}

it('registers the deploy command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('deploy')
        ->and($commands['deploy']->getDescription())
        ->toBe('Deploy the project with Deployer');
});

it('runs the project Deployer binary with deployment options', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeDeployerBinary($directory, $files);

        $this->artisan('deploy', [
            'selector' => ['production', 'role=web'],
            '--file' => 'deploy.production.php',
            '--tag' => 'v1.2.3',
            '--revision' => 'abc1234',
            '--branch' => '1.x',
            '--option' => ['keep_releases=3', 'ssh_multiplexing=false'],
            '--limit' => '2',
            '--no-hooks' => true,
            '--plan' => true,
            '--start-from' => 'deploy:vendors',
            '--log' => 'deploy.log',
            '--profile' => 'deploy.csv',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Deployer output')
            ->expectsOutputToContain('Deployer completed successfully.')
            ->assertSuccessful();

        expect(trim($files->get($directory.'/deployer.log')))
            ->toBe(implode(' ', [
                '--file=deploy.production.php',
                'deploy',
                '--tag=v1.2.3',
                '--revision=abc1234',
                '--branch=1.x',
                '--limit=2',
                '--start-from=deploy:vendors',
                '--log=deploy.log',
                '--profile=deploy.csv',
                '--option=keep_releases=3',
                '--option=ssh_multiplexing=false',
                '--no-hooks',
                '--plan',
                '--no-interaction',
                'production',
                'role=web',
            ]))
            ->and(trim($files->get($directory.'/maintainer-tasks-path.log')))
            ->toBe(realpath($directory).'/vendor/artisan-toolbox/maintainer/app/Deployer/tasks.php');
    });
});

it('returns the Deployer exit code', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeDeployerBinary($directory, $files, 42);

        $this->artisan('deploy', ['--no-interaction' => true])
            ->expectsOutputToContain('Deployer failed with exit code 42.')
            ->assertExitCode(42);
    });
});

it('rejects an empty Deployer configuration override', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeDeployerBinary($directory, $files);

        $this->artisan('deploy', [
            '--option' => [null],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('A Deployer configuration override is invalid.')
            ->assertFailed();

        expect($files->exists($directory.'/deployer.log'))->toBeFalse();
    });
});

it('reports when Deployer is not installed in the project', function () {
    withinTemporaryProject(function () {
        $this->artisan('deploy', ['--no-interaction' => true])
            ->expectsOutputToContain('Deployer is not installed in the project.')
            ->assertFailed();
    });
});

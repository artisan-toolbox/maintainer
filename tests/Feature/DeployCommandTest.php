<?php

use App\Support\Ssh\Ed25519KeyGenerator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;

function installFakeDeployerBinary(string $directory, Filesystem $files, int $exitCode = 0): void
{
    $windows = PHP_OS_FAMILY === 'Windows';
    $path = $directory.'/vendor/bin/dep'.($windows ? '.bat' : '');
    $log = $directory.'/deployer.log';
    $contribPathLog = $directory.'/maintainer-contrib-path.log';
    $identityPathLog = $directory.'/maintainer-ssh-identity-path.log';
    $identityContentsLog = $directory.'/maintainer-ssh-identity-contents.log';
    $identityPermissionsLog = $directory.'/maintainer-ssh-identity-permissions.log';
    $script = $windows
        ? "@echo off\r\necho %*>\"{$log}\"\r\necho %MAINTAINER_CONTRIB%>\"{$contribPathLog}\"\r\nif defined MAINTAINER_SSH_IDENTITY_FILE (\r\n    echo %MAINTAINER_SSH_IDENTITY_FILE%>\"{$identityPathLog}\"\r\n    type \"%MAINTAINER_SSH_IDENTITY_FILE%\" > \"{$identityContentsLog}\"\r\n) else (\r\n    type nul > \"{$identityPathLog}\"\r\n)\r\necho Deployer output\r\nexit /b {$exitCode}\r\n"
        : "#!/bin/sh\nprintf '%s\\n' \"\$*\" > ".escapeshellarg($log)."\nprintf '%s\\n' \"\$MAINTAINER_CONTRIB\" > ".escapeshellarg($contribPathLog)."\nprintf '%s\\n' \"\$MAINTAINER_SSH_IDENTITY_FILE\" > ".escapeshellarg($identityPathLog)."\nif [ -n \"\$MAINTAINER_SSH_IDENTITY_FILE\" ]; then\n    cat \"\$MAINTAINER_SSH_IDENTITY_FILE\" > ".escapeshellarg($identityContentsLog)."\n    ls -l \"\$MAINTAINER_SSH_IDENTITY_FILE\" > ".escapeshellarg($identityPermissionsLog)."\nfi\nprintf 'Deployer output\\n'\nexit {$exitCode}\n";

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

it('registers the deploy unlock command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('deploy:unlock')
        ->and($commands['deploy:unlock']->getDescription())
        ->toBe('Unlock a failed Deployer deployment');
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
            ->and(trim($files->get($directory.'/maintainer-contrib-path.log')))
            ->toBe(realpath($directory).'/vendor/artisan-toolbox/maintainer/app/Deployer/contrib.php')
            ->and(trim($files->get($directory.'/maintainer-ssh-identity-path.log')))
            ->toBe('')
            ->and($files->exists($directory.'/maintainer-ssh-identity-contents.log'))
            ->toBeFalse();
    });
});

it('injects a generated SSH key through a restricted temporary file and always deletes it', function () {
    forgetTestEnvironmentVariable('APP_KEY');

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
            $privateKey = resolve(Ed25519KeyGenerator::class)->generatePrivateKey('owner@example.com');

            putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
                'ssh_key' => Crypt::encryptString($privateKey),
            ]);
            foreach ([0, 42] as $exitCode) {
                installFakeDeployerBinary($directory, $files, $exitCode);

                $this->artisan('deploy', ['--no-interaction' => true])
                    ->assertExitCode($exitCode);

                $identityPath = trim($files->get($directory.'/maintainer-ssh-identity-path.log'));

                expect($identityPath)->not->toBe('')
                    ->and($files->get($directory.'/maintainer-ssh-identity-contents.log'))
                    ->toBe($privateKey)
                    ->and($files->exists($identityPath))
                    ->toBeFalse();

                if (PHP_OS_FAMILY !== 'Windows') {
                    expect($files->get($directory.'/maintainer-ssh-identity-permissions.log'))
                        ->toStartWith('-rw-------');
                }
            }
        });
    } finally {
        forgetTestEnvironmentVariable('APP_KEY');
    }
});

it('unlocks a failed deployment through the project Deployer binary', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeDeployerBinary($directory, $files);

        $this->artisan('deploy:unlock', [
            'selector' => ['production'],
            '--file' => 'deploy.production.php',
            '--option' => ['ssh_multiplexing=false'],
            '--limit' => '1',
            '--no-hooks' => true,
            '--plan' => true,
            '--log' => 'unlock.log',
            '--profile' => 'unlock.csv',
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Deployer output')
            ->expectsOutputToContain('Deployer deployment unlocked successfully.')
            ->assertSuccessful();

        expect(trim($files->get($directory.'/deployer.log')))->toBe(implode(' ', [
            '--file=deploy.production.php',
            'deploy:unlock',
            '--limit=1',
            '--log=unlock.log',
            '--profile=unlock.csv',
            '--option=ssh_multiplexing=false',
            '--no-hooks',
            '--plan',
            '--no-interaction',
            'production',
        ]));
    });
});

it('returns the Deployer exit code when unlocking fails', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeDeployerBinary($directory, $files, 42);

        $this->artisan('deploy:unlock', ['--no-interaction' => true])
            ->expectsOutputToContain('Deployer unlock failed with exit code 42.')
            ->assertExitCode(42);
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

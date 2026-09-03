<?php

use App\Support\Quality\QualityCheckPrompt;
use App\Support\Quality\QualityCommitPrompt;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusTest;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVueTscCheck;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function installFakeComposerQualityBinary(string $directory, Filesystem $files, string $binary, int $exitCode = 0): void
{
    $windows = PHP_OS_FAMILY === 'Windows';
    $path = $directory.'/vendor/bin/'.$binary.($windows ? '.bat' : '');
    $script = $windows
        ? "@echo off\r\necho {$binary} %*>>\"{$directory}/quality.log\"\r\nexit /b {$exitCode}\r\n"
        : "#!/missing/php\n<?php\nfile_put_contents("
            .var_export($directory.'/quality.log', true)
            .", '"
            .$binary
            ." '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);\nexit({$exitCode});\n";

    $files->put($path, $script);
    chmod($path, 0755);
}

function installFakeNodeQualityBinary(string $directory, Filesystem $files, string $binary): void
{
    $files->ensureDirectoryExists($directory.'/node_modules/.bin');
    $windows = PHP_OS_FAMILY === 'Windows';
    $path = $directory.'/node_modules/.bin/'.$binary.($windows ? '.cmd' : '');
    $script = $windows
        ? "@echo off\r\necho {$binary} %*>>\"{$directory}/quality.log\"\r\n"
        : "#!/bin/sh\nprintf '%s\\n' \"{$binary} \$*\" >> ".escapeshellarg($directory.'/quality.log')."\n";

    $files->put($path, $script);
    chmod($path, 0755);
}

function normalizedQualityLog(string $directory, Filesystem $files): string
{
    return str_replace(
        ["\r\n", '\\', '"'],
        ["\n", '/', ''],
        $files->get($directory.'/quality.log'),
    );
}

it('runs every configured fixer and discovers package scripts by their contents', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pint');
        installFakeComposerQualityBinary($directory, $files, 'rector');
        installFakeNodeQualityBinary($directory, $files, 'vp');
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/rector.php', "<?php\n");
        $files->put($directory.'/package.json', json_encode([
            'scripts' => [
                'paca-tatu' => 'vp check --fix',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->artisan('quality:fix', ['--no-interaction' => true])
            ->expectsOutputToContain('Code quality fixes completed successfully: 3 run, 0 skipped.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);

        expect(normalizedQualityLog($directory, $files))->toBe(implode("\n", [
            "pint --config {$normalizedDirectory}/pint.json",
            "rector process --config {$normalizedDirectory}/rector.php",
            'vp check --fix',
            '',
        ]));
    });
});

it('runs every configured CI check in order', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        foreach (['pest', 'pint', 'phpstan'] as $binary) {
            installFakeComposerQualityBinary($directory, $files, $binary);
        }

        foreach (['vp', 'vue-tsc'] as $binary) {
            installFakeNodeQualityBinary($directory, $files, $binary);
        }

        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/phpunit.xml.dist', "<phpunit/>\n");
        $files->put($directory.'/phpstan.neon.dist', "parameters:\n");
        $files->put($directory.'/package.json', json_encode([
            'packageManager' => 'npm@11.0.0',
            'scripts' => [
                'frontend-quality' => 'vp check',
                'paca-tatu' => 'vp test run',
                'types:vue' => 'vue-tsc --noEmit',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->artisan('quality:check', ['--no-interaction' => true])
            ->expectsOutputToContain('CI checks completed successfully: 6 run, 0 skipped.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);

        expect(normalizedQualityLog($directory, $files))->toBe(implode("\n", [
            "pest --configuration {$normalizedDirectory}/phpunit.xml.dist",
            "pint --test --config {$normalizedDirectory}/pint.json",
            'vp check',
            'vp test run',
            'vue-tsc --noEmit',
            "phpstan analyse --configuration {$normalizedDirectory}/phpstan.neon.dist --memory-limit=2G",
            '',
        ]));
    });
});

it('offers to run configured checks after an interactive fix', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $checkWasOffered = false;
        $commitWasOffered = false;
        app()->instance(QualityCheckPrompt::class, new QualityCheckPrompt(
            static function () use (&$checkWasOffered): bool {
                $checkWasOffered = true;

                return true;
            },
        ));
        app()->instance(QualityCommitPrompt::class, new QualityCommitPrompt(
            static function () use (&$commitWasOffered): bool {
                $commitWasOffered = true;

                return false;
            },
        ));
        installFakeComposerQualityBinary($directory, $files, 'pint');
        installFakeComposerQualityBinary($directory, $files, 'pest');
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'fix' => [RunsPintFix::class],
                'test' => [RunsPestCheck::class],
            ],
        ]);

        foreach ([
            ['init'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Create quality fixture'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        $this->artisan('quality:fix', ['--tool' => ['pint']])
            ->expectsOutputToContain('CI checks completed successfully: 1 run, 0 skipped.')
            ->assertSuccessful();

        expect($checkWasOffered)->toBeTrue()
            ->and($commitWasOffered)->toBeTrue()
            ->and(normalizedQualityLog($directory, $files))
            ->toContain('pint --config')
            ->toContain('pest --configuration');
    });
});

it('returns a failed accepted check before offering a commit after an interactive fix', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        app()->instance(QualityCheckPrompt::class, new QualityCheckPrompt(
            static fn (): bool => true,
        ));
        installFakeComposerQualityBinary($directory, $files, 'pint');
        installFakeComposerQualityBinary($directory, $files, 'pest', 8);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'fix' => [RunsPintFix::class],
                'test' => [RunsPestCheck::class],
            ],
        ]);

        $this->artisan('quality:fix', ['--tool' => ['pint']])
            ->expectsOutputToContain('Pest failed with exit code 8.')
            ->assertExitCode(8);
    });
});

it('warns and skips PHP tools without both their binary and configuration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pint');
        $files->put($directory.'/rector.php', "<?php\n");

        $this->artisan('quality:fix', ['--no-interaction' => true])
            ->expectsOutputToContain('Skipped Pint: no supported configuration file was found (pint.json).')
            ->expectsOutputToContain('Skipped Rector: vendor/bin/rector is not installed.')
            ->expectsOutputToContain('Skipped Vite+ Check: package.json was not found.')
            ->expectsOutputToContain('Code quality fixes completed successfully: 0 run, 3 skipped.')
            ->assertSuccessful();

        expect($files->exists($directory.'/quality.log'))->toBeFalse();
    });
});

it('requires both an installed Node binary and a matching package script', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/package.json', json_encode([
            'scripts' => [
                'paca-tatu' => 'vp test run',
                'types' => 'vue-tsc --noEmit',
            ],
        ], JSON_THROW_ON_ERROR));
        installFakeNodeQualityBinary($directory, $files, 'vp');
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'test' => [
                    RunsVitePlusCheck::class,
                    RunsVitePlusTest::class,
                    RunsVueTscCheck::class,
                ],
            ],
        ]);

        $this->artisan('quality:check', ['--no-interaction' => true])
            ->expectsOutputToContain('Skipped Vite+ Check: no package.json script runs `vp check`.')
            ->expectsOutputToContain('Skipped vue-tsc: node_modules/.bin/vue-tsc is not installed.')
            ->expectsOutputToContain('CI checks completed successfully: 1 run, 2 skipped.')
            ->assertSuccessful();

        expect(trim(normalizedQualityLog($directory, $files)))->toBe('vp test run');
    });
});

it('runs only a selected configured tool', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pest');
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");

        $this->artisan('quality:check', [
            '--tool' => ['pest'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('CI checks completed successfully: 1 run, 0 skipped.')
            ->assertSuccessful();

        expect(normalizedQualityLog($directory, $files))->toContain('pest --configuration');
    });
});

it('replaces default command lists with project configuration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pest');
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'test' => [RunsPestCheck::class],
            ],
        ]);

        $this->artisan('quality:check', ['--no-interaction' => true])
            ->expectsOutputToContain('CI checks completed successfully: 1 run, 0 skipped.')
            ->assertSuccessful();
    });
});

it('rejects selected tools that are not configured for the workflow', function () {
    withinTemporaryProject(function () {
        $this->artisan('quality:fix', [
            '--tool' => ['phpstan'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Every selected tool must be one of: pint, rector, vite-plus-check.')
            ->assertFailed();
    });
});

it('stops the workflow and returns a tool failure exit code', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pint', 7);
        $files->put($directory.'/pint.json', "{}\n");

        $this->artisan('quality:fix', [
            '--tool' => ['pint'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Pint failed with exit code 7.')
            ->assertExitCode(7);
    });
});

it('keeps Pest parallel execution and PHPStan memory configuration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pest');
        installFakeComposerQualityBinary($directory, $files, 'phpstan');
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        $files->put($directory.'/phpstan.neon', "parameters:\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'test' => [
                    RunsPestCheck::class,
                    RunsPhpStanCheck::class,
                ],
                'pest' => ['parallel' => true],
                'phpstan' => ['memory_limit' => '4G'],
            ],
        ]);

        $this->artisan('quality:check', ['--no-interaction' => true])->assertSuccessful();

        expect(normalizedQualityLog($directory, $files))
            ->toContain('pest --configuration')
            ->toContain('--parallel')
            ->toContain('--memory-limit=4G');
    });
});

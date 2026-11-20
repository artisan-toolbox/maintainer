<?php

use App\Support\Git\CommitMessageMode;
use App\Support\Git\CommitMessageReviewer;
use App\Support\Git\CommitWorkflowPrompts;
use App\Support\Git\GitFileSelector;
use App\Support\Quality\QualityCheckPrompt;
use App\Support\Quality\QualityCommitPrompt;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusTest;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVueTscCheck;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\SelectPrompt;
use Symfony\Component\Console\Command\Command;
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

        $configuration = require dirname(__DIR__, 2).'/config/maintainer.php';
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', $configuration);

        $this->artisan('quality:fix', ['--no-interaction' => true])
            ->expectsOutputToContain('Code quality fixes completed successfully: 3 run, 0 skipped.')
            ->assertSuccessful();

        $resolvedDirectory = realpath($directory);
        assert(is_string($resolvedDirectory));
        $normalizedDirectory = str_replace('\\', '/', $resolvedDirectory);

        expect(normalizedQualityLog($directory, $files))->toBe(implode("\n", [
            "rector process --config {$normalizedDirectory}/rector.php",
            "pint --config {$normalizedDirectory}/pint.json",
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

it('runs the selected commit action after interactive checks pass', function (string $choice, int $releaseExitCode, bool $runFixFirst = false) {
    ConfirmPrompt::fallbackWhen(true);
    SelectPrompt::fallbackWhen(true);

    withinTemporaryProject(function (string $directory, Filesystem $files) use ($choice, $releaseExitCode, $runFixFirst): void {
        installFakeComposerQualityBinary($directory, $files, 'pest');
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        $files->put($directory.'/.gitignore', "/vendor/\n/quality.log\n");
        $files->put($directory.'/example.txt', "original\n");

        if ($runFixFirst) {
            installFakeComposerQualityBinary($directory, $files, 'pint');
            $files->put($directory.'/pint.json', "{}\n");
        }

        foreach ([
            ['init', '--initial-branch=1.x'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Create quality fixture'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        $files->put($directory.'/example.txt', "updated\n");
        $pushOffered = false;
        $releaseStarted = false;
        app()->instance(CommitWorkflowPrompts::class, new CommitWorkflowPrompts(
            reviewDiff: static fn (): bool => false,
            messageMode: static fn (): CommitMessageMode => CommitMessageMode::Manual,
            pushCommit: static function () use (&$pushOffered): bool {
                $pushOffered = true;

                return false;
            },
        ));
        app()->instance(CommitMessageReviewer::class, new CommitMessageReviewer(static fn (): string => 'fix: update fixture'));
        app()->instance(GitFileSelector::class, new GitFileSelector(static fn (MultiSearchPrompt $prompt): array => array_keys($prompt->values)));
        Artisan::registerCommand(new Command('release:create')->setCode(function () use ($directory, &$releaseStarted, $releaseExitCode): int {
            $releaseStarted = true;
            expect(new Process(['git', 'show', 'HEAD:example.txt'], $directory)->mustRun()->getOutput())->toBe("updated\n");

            return $releaseExitCode;
        }));

        $quality = $runFixFirst
            ? $this->artisan('quality:fix', ['--tool' => ['pint']])
                ->expectsConfirmation('Would you like to run the configured code-quality checks now?', 'yes')
            : $this->artisan('quality:check', ['--tool' => ['pest']]);

        $quality
            ->expectsConfirmation('The project has changes. Would you like to create a commit now?', 'yes')
            ->expectsChoice('What would you like to do after creating the commit?', $choice, [
                'commit' => 'Commit',
                'release' => 'Commit and release',
            ])
            ->assertExitCode($releaseExitCode)
            ->run();

        expect($releaseStarted)->toBe($choice === 'release')
            ->and($pushOffered)->toBe($choice === 'commit')
            ->and(new Process(['git', 'show', 'HEAD:example.txt'], $directory)->mustRun()->getOutput())->toBe("updated\n");
    });
})->with([
    'commit' => ['commit', 0],
    'commit and release' => ['release', 0],
    'commit and release after a fix and check' => ['release', 0, true],
    'release failure keeps the commit' => ['release', 8],
]);

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

it('runs the selected PHPStan check once in strict mode', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'phpstan');
        $files->put($directory.'/phpstan.neon', "parameters:\n");

        $this->artisan('quality:check', [
            '--strict' => true,
            '--tool' => ['phpstan'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('CI checks completed successfully: 1 run, 0 skipped.')
            ->assertSuccessful();

        $executed = explode("\n", trim(normalizedQualityLog($directory, $files)));

        expect($executed)->toHaveCount(1)
            ->and($executed[0])->toStartWith('phpstan analyse --configuration ');
    });
});

it('fails strict CI when an expected tool cannot run', function (bool $installBinary, string $reason) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($installBinary, $reason) {
        installFakeComposerQualityBinary($directory, $files, 'pest');
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");

        if ($installBinary) {
            installFakeComposerQualityBinary($directory, $files, 'phpstan');
        }

        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => ['test' => [RunsPestCheck::class, RunsPhpStanCheck::class]],
        ]);

        $this->artisan('quality:check', ['--strict' => true, '--no-interaction' => true])
            ->expectsOutputToContain("Required PHPStan could not run: {$reason}")
            ->assertFailed();
    });
})->with([
    'binary missing' => [false, 'vendor/bin/phpstan is not installed'],
    'configuration missing' => [true, 'no supported configuration file was found'],
]);

it('fails strict CI when no tools are configured', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => ['test' => []],
        ]);

        $this->artisan('quality:check', ['--strict' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Strict mode requires at least one configured tool in quality.test.')
            ->assertFailed();
    });
});

it('preserves the exit code of a failed strict check', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pest', 7);
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");

        $this->artisan('quality:check', [
            '--strict' => true,
            '--tool' => ['pest'],
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Pest failed with exit code 7.')
            ->assertExitCode(7);
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
            ->expectsOutputToContain('Every selected tool must be one of: rector, pint, vite-plus-check.')
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

it('applies Pest worker and PHPStan memory configuration', function (bool $parallel, ?int $processes) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($parallel, $processes) {
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
                'pest' => ['parallel' => $parallel, 'processes' => $processes],
                'phpstan' => ['memory_limit' => '4G'],
            ],
        ]);

        $this->artisan('quality:check', ['--no-interaction' => true])->assertSuccessful();

        $log = normalizedQualityLog($directory, $files);

        expect($log)
            ->toContain('pest --configuration')
            ->toContain('--memory-limit=4G')
            ->and(str_contains($log, '--parallel'))->toBe($parallel)
            ->and(str_contains($log, '--processes=4'))->toBe($parallel && $processes === 4);
    });
})->with([
    'automatic worker count' => [true, null],
    'fixed worker count' => [true, 4],
    'sequential tests' => [false, 4],
]);

it('rejects an invalid Pest worker count before running tests', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installFakeComposerQualityBinary($directory, $files, 'pest');
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'pest' => ['parallel' => true, 'processes' => 0],
            ],
        ]);

        $this->artisan('quality:check', ['--no-interaction' => true, '--strict' => true, '--tool' => ['pest']])
            ->expectsOutputToContain('quality.pest.processes must be a positive integer or null.')
            ->assertFailed();
    });
});

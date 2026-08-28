<?php

use App\Support\Configuration\MaintainerConfiguration;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Env;

use function Illuminate\Filesystem\join_paths;

function withinTemporaryConfigurationProject(Closure $callback): void
{
    $files = new Filesystem;
    $originalWorkingDirectory = getcwd();
    $temporaryDirectory = temporaryTestDirectory('maintainer-config-');
    $hadComposerAutoloadPath = array_key_exists('_composer_autoload_path', $GLOBALS);
    $originalComposerAutoloadPath = $GLOBALS['_composer_autoload_path'] ?? null;

    $files->makeDirectory($temporaryDirectory.'/vendor', recursive: true);
    $files->put($temporaryDirectory.'/composer.json', "{}\n");
    $files->put($temporaryDirectory.'/vendor/autoload.php', "<?php\n");

    $GLOBALS['_composer_autoload_path'] = $temporaryDirectory.'/vendor/autoload.php';
    chdir($temporaryDirectory);

    try {
        $callback($temporaryDirectory, $files);
    } finally {
        chdir($originalWorkingDirectory);

        if ($hadComposerAutoloadPath) {
            $GLOBALS['_composer_autoload_path'] = $originalComposerAutoloadPath;
        } else {
            unset($GLOBALS['_composer_autoload_path']);
        }

        $files->deleteDirectory($temporaryDirectory);
    }
}

it('reads PHP configuration values using dot notation and current defaults', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'quality' => [
                'phpstan' => [
                    'level' => 8,
                ],
            ],
        ]);

        $configuration = resolve(MaintainerConfiguration::class);

        expect($configuration->configMissing())->toBeFalse()
            ->and($configuration->path())->toEndWith(join_paths('config', 'dev_maintainer.php'))
            ->and($configuration->get('quality.phpstan.level'))->toBe(8)
            ->and($configuration->get('quality.phpstan.memory_limit'))->toBe('2G')
            ->and($configuration->get('quality.pest.parallel'))->toBeFalse()
            ->and($configuration->get('git.diff.output_format'))->toBe('line_by_line')
            ->and($configuration->get('quality.pint.preset', 'laravel'))->toBe('laravel')
            ->and($configuration->has('quality.phpstan.level'))->toBeTrue()
            ->and(maintainer_config('quality.phpstan.level'))->toBe(8)
            ->and(maintainer_config('missing', 'fallback'))->toBe('fallback')
            ->and(maintainer_config_missing())->toBeFalse();
    });
});

it('uses attributes to enforce its lifecycle and getter usage', function () {
    $configuration = resolve(MaintainerConfiguration::class);
    $reflection = new ReflectionClass(MaintainerConfiguration::class);

    expect(resolve(MaintainerConfiguration::class))->toBe($configuration)
        ->and($reflection->getAttributes(Singleton::class))->toHaveCount(1)
        ->and($reflection->getMethod('get')->getAttributes(NoDiscard::class))->toHaveCount(1);
});

it('uses the current default configuration when the project file is missing', function () {
    withinTemporaryConfigurationProject(function () {
        $configuration = resolve(MaintainerConfiguration::class);
        $defaults = defaultMaintainerConfigurationFixture();

        expect($configuration->configMissing())->toBeTrue()
            ->and($configuration->all())->toBe($defaults)
            ->and(maintainer_config())->toBe($defaults)
            ->and(maintainer_config_missing())->toBeTrue();
    });
});

it('removes the development prefix in the production environment used by builds', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $this->app['env'] = 'production';
        putPhpConfiguration($files, $directory.'/config/maintainer.php', [
            'version' => 'production',
        ]);

        $configuration = resolve(MaintainerConfiguration::class);

        expect($configuration->path())->toEndWith(join_paths('config', 'maintainer.php'))
            ->and($configuration->get('version'))->toBe('production');
    });
});

it('rejects an unsafe development configuration prefix', function () {
    withinTemporaryConfigurationProject(function () {
        config()->set('app.user_config_prefix', '../');

        expect(fn () => resolve(MaintainerConfiguration::class)->path())
            ->toThrow(RuntimeException::class, 'app.user_config_prefix must contain only');
    });
});

it('allows project PHP configuration to override defaults', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'git' => [
                'diff' => [
                    'output_format' => 'side_by_side',
                ],
            ],
        ]);

        expect(maintainer_config('git.diff.output_format'))->toBe('side_by_side');
    });
});

it('loads Maintainer configuration values from the consuming project environment file', function () {
    $variable = 'MAINTAINER_GIT_DIFF_OUTPUT_FORMAT';
    forgetTestEnvironmentVariable($variable);

    try {
        withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', "MAINTAINER_GIT_DIFF_OUTPUT_FORMAT=side_by_side\n");

            expect(maintainer_config('git.diff.output_format'))->toBe('side_by_side');
        });
    } finally {
        forgetTestEnvironmentVariable($variable);
    }
});

it('enables parallel Pest execution from the consuming project environment file', function () {
    $variable = 'MAINTAINER_PEST_PARALLEL';
    forgetTestEnvironmentVariable($variable);

    try {
        withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', "MAINTAINER_PEST_PARALLEL=true\n");

            expect(maintainer_config('quality.pest.parallel'))->toBeTrue();
        });
    } finally {
        forgetTestEnvironmentVariable($variable);
    }
});

it('restores the process environment after evaluating project configuration', function () {
    $variable = 'MAINTAINER_TEST_SCOPED_VALUE';
    forgetTestEnvironmentVariable($variable);

    try {
        withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) use ($variable) {
            $files->put($directory.'/.env', "{$variable}=from-project\n");
            $files->ensureDirectoryExists($directory.'/config');
            $files->put(
                $directory.'/config/dev_maintainer.php',
                "<?php\n\nreturn ['scoped_value' => env('{$variable}')];\n",
            );

            expect(maintainer_config('scoped_value'))->toBe('from-project')
                ->and(getenv($variable))->toBeFalse()
                ->and($_ENV)->not->toHaveKey($variable)
                ->and($_SERVER)->not->toHaveKey($variable);
        });
    } finally {
        forgetTestEnvironmentVariable($variable);
    }
});

it('keeps system environment variables ahead of the project environment file', function () {
    $variable = 'MAINTAINER_PHPSTAN_MEMORY_LIMIT';
    forgetTestEnvironmentVariable($variable);
    Env::getRepository()->set($variable, '6G');

    try {
        withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', "MAINTAINER_PHPSTAN_MEMORY_LIMIT=4G\n");

            expect(maintainer_config('quality.phpstan.memory_limit'))->toBe('6G');
        });
    } finally {
        forgetTestEnvironmentVariable($variable);
    }
});

it('reports invalid consuming project environment files', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/.env', "BROKEN KEY=value\n");

        expect(fn () => maintainer_config())
            ->toThrow(RuntimeException::class, 'Unable to load the project environment file');
    });
});

it('caches values until the PHP configuration is refreshed', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/config/dev_maintainer.php';
        putPhpConfiguration($files, $path, ['version' => 1]);

        $configuration = resolve(MaintainerConfiguration::class);

        expect($configuration->get('version'))->toBe(1)
            ->and($configuration->get('git.diff.output_format'))->toBe('line_by_line');

        putPhpConfiguration($files, $path, ['version' => 2]);

        expect($configuration->get('version'))->toBe(1)
            ->and($configuration->refresh())->toBe([
                ...defaultMaintainerConfigurationFixture(),
                'version' => 2,
            ])
            ->and($configuration->get('version'))->toBe(2);
    });
});

it('rejects a PHP configuration that does not return an associative array', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->ensureDirectoryExists($directory.'/config');
        $files->put($directory.'/config/dev_maintainer.php', "<?php\n\nreturn 'invalid';\n");

        expect(fn () => maintainer_config())
            ->toThrow(RuntimeException::class, 'config/dev_maintainer.php must return an associative array.');
    });
});

it('continues reading legacy JSON configuration during migration', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "quality": {
                    "phpstan": {
                        "level": 7
                    }
                }
            }
            JSON.PHP_EOL);

        expect(maintainer_config('quality.phpstan.level'))->toBe(7)
            ->and(maintainer_config('quality.phpstan.memory_limit'))->toBe('2G')
            ->and(maintainer_config_missing())->toBeFalse();
    });
});

it('rejects invalid legacy JSON', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', '{invalid');

        expect(fn () => maintainer_config())
            ->toThrow(RuntimeException::class, 'maintainer.json contains invalid JSON');
    });
});

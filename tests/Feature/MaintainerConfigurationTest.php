<?php

use App\Support\Configuration\MaintainerConfiguration;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

function withinTemporaryConfigurationProject(Closure $callback): void
{
    $files = new Filesystem;
    $originalWorkingDirectory = getcwd();
    $temporaryDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-config-'.Str::uuid();
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

it('reads configuration values using dot notation and defaults', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "quality": {
                    "phpstan": {
                        "level": 8
                    }
                }
            }
            JSON.PHP_EOL);

        $configuration = resolve(MaintainerConfiguration::class);

        expect($configuration->configMissing())->toBeFalse()
            ->and($configuration->get('quality.phpstan.level'))->toBe(8)
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

it('uses the default configuration when the project file is missing', function () {
    withinTemporaryConfigurationProject(function () {
        $configuration = resolve(MaintainerConfiguration::class);
        $defaults = defaultMaintainerConfigurationFixture();

        expect($configuration->configMissing())->toBeTrue()
            ->and($configuration->all())->toBe($defaults)
            ->and(maintainer_config())->toBe($defaults)
            ->and(maintainer_config_missing())->toBeTrue();
    });
});

it('allows project configuration to override defaults', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "git": {
                    "diff": {
                        "output_format": "side_by_side"
                    }
                }
            }
            JSON.PHP_EOL);

        expect(maintainer_config('git.diff.output_format'))->toBe('side_by_side');
    });
});

it('caches values until the configuration is refreshed', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/maintainer.json';
        $files->put($path, "{\"version\": 1}\n");

        $configuration = resolve(MaintainerConfiguration::class);

        expect($configuration->get('version'))->toBe(1)
            ->and($configuration->get('git.diff.output_format'))->toBe('line_by_line');

        $files->put($path, "{\"version\": 2}\n");

        expect($configuration->get('version'))->toBe(1)
            ->and($configuration->refresh())->toBe([
                'ai' => [
                    'providers' => [
                        'commit_message' => 'openai',
                        'release_notes' => 'openai',
                        'release_changelog_update' => 'openai',
                    ],
                ],
                'git' => [
                    'diff' => [
                        'output_format' => 'line_by_line',
                    ],
                ],
                'version' => 2,
            ])
            ->and($configuration->get('version'))->toBe(2);
    });
});

it('rejects invalid JSON', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', '{invalid');

        expect(fn () => maintainer_config())
            ->toThrow(RuntimeException::class, 'maintainer.json contains invalid JSON');
    });
});

it('rejects a configuration whose root is not an object', function () {
    withinTemporaryConfigurationProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', "[]\n");

        expect(fn () => maintainer_config())
            ->toThrow(RuntimeException::class, 'maintainer.json must contain a JSON object.');
    });
});

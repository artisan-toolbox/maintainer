<?php

use Illuminate\Filesystem\Filesystem;

it('creates PHP configuration files in the project config directory', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')
            ->expectsOutputToContain('Created Maintainer configuration at config/dev_maintainer.php and protected its secrets file.')
            ->assertSuccessful();

        $configurationPath = $directory.'/config/dev_maintainer.php';
        $secretsPath = $directory.'/config/dev_maintainer_secrets.php';
        $configuration = require $configurationPath;
        $secrets = require $secretsPath;

        expect($configuration)
            ->toBe(defaultMaintainerConfigurationFixture())
            ->and($files->get($configurationPath))->toStartWith("<?php\n\nreturn [")
            ->and($secretsPath)->toBeFile()
            ->and($secrets)->toHaveKey('ai_providers.anthropic.key')
            ->and($files->get($directory.'/.gitignore'))
            ->toBe("config/dev_maintainer_secrets.php\n")
            ->and($files->exists($directory.'/maintainer.json'))->toBeFalse()
            ->and($files->exists($directory.'/maintainer_secrets.json'))->toBeFalse();
    });
});

it('creates configuration in the project root when run from vendor bin', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')->assertSuccessful();

        expect($files->exists($directory.'/config/dev_maintainer.php'))->toBeTrue()
            ->and($files->exists($directory.'/vendor/bin/config/dev_maintainer.php'))->toBeFalse();
    }, workingDirectory: 'vendor/bin');
});

it('finds the project root from a nested directory without Composer proxy metadata', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')->assertSuccessful();

        expect($files->exists($directory.'/config/dev_maintainer.php'))->toBeTrue()
            ->and($files->exists($directory.'/packages/example/config/dev_maintainer.php'))->toBeFalse();
    }, workingDirectory: 'packages/example', exposeComposerProxy: false);
});

it('fails when run outside a Composer project', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->delete($directory.'/composer.json');

        $this->artisan('init')
            ->expectsOutputToContain('Unable to locate the project root.')
            ->assertFailed();

        expect($files->exists($directory.'/config/dev_maintainer.php'))->toBeFalse();
    }, exposeComposerProxy: false);
});

it('does not overwrite an existing configuration file', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/config/dev_maintainer.php';
        putPhpConfiguration($files, $path, ['existing' => true]);

        $this->artisan('init')
            ->expectsOutputToContain('config/dev_maintainer.php already exists.')
            ->assertFailed();

        expect(require $path)->toBe(['existing' => true]);
    });
});

it('overwrites configuration when forced without overwriting secrets', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/config/dev_maintainer.php';
        $secretsPath = $directory.'/config/dev_maintainer_secrets.php';
        putPhpConfiguration($files, $path, ['existing' => true]);
        putPhpConfiguration($files, $secretsPath, [
            'ai_providers' => [
                'openai' => ['key' => 'keep-me'],
            ],
        ]);

        $this->artisan('init', ['--force' => true])
            ->expectsOutputToContain('config/dev_maintainer_secrets.php already exists and was not overwritten.')
            ->assertSuccessful();

        expect(require $path)->toBe(defaultMaintainerConfigurationFixture())
            ->and(require $secretsPath)->toHaveKey('ai_providers.openai.key', 'keep-me');
    });
});

it('adds the secrets file to an existing gitignore only once', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/.gitignore', "/vendor\n");

        $this->artisan('init')->assertSuccessful();
        $this->artisan('init', ['--force' => true])->assertSuccessful();

        expect($files->get($directory.'/.gitignore'))->toBe(implode("\n", [
            '/vendor',
            'config/dev_maintainer_secrets.php',
            '',
        ]));
    });
});

it('publishes every Laravel AI provider in the secrets template', function () {
    withinTemporaryProject(function (string $directory) {
        $this->artisan('init')->assertSuccessful();

        $secrets = require $directory.'/config/dev_maintainer_secrets.php';

        expect(array_keys($secrets['ai_providers']))->toBe([
            'anthropic',
            'azure',
            'bedrock',
            'cohere',
            'deepseek',
            'eleven',
            'gemini',
            'groq',
            'jina',
            'mistral',
            'ollama',
            'openai',
            'openai-compatible',
            'openrouter',
            'voyageai',
            'xai',
        ]);
    });
});

it('migrates legacy JSON configuration and secrets without losing values', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', json_encode([
            'quality' => ['phpstan' => ['memory_limit' => '4G']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        $files->put($directory.'/maintainer_secrets.json', json_encode([
            'ai_providers' => ['openai' => ['key' => 'keep-me']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->artisan('init')
            ->expectsOutputToContain('Migrated configuration')
            ->expectsOutputToContain('Migrated secrets')
            ->assertSuccessful();

        expect(require $directory.'/config/dev_maintainer.php')
            ->toHaveKey('quality.phpstan.memory_limit', '4G')
            ->and(require $directory.'/config/dev_maintainer_secrets.php')
            ->toHaveKey('ai_providers.openai.key', 'keep-me')
            ->and($files->exists($directory.'/maintainer.json'))->toBeFalse()
            ->and($files->exists($directory.'/maintainer_secrets.json'))->toBeFalse();
    });
});

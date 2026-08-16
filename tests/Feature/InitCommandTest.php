<?php

use Illuminate\Filesystem\Filesystem;

it('creates a configuration file in the current project', function () {
    withinTemporaryProject(function (string $directory) {
        $this->artisan('init')
            ->expectsOutputToContain('Created Maintainer configuration and protected its secrets file.')
            ->assertSuccessful();

        $configuration = file_get_contents($directory.'/maintainer.json');
        $secrets = file_get_contents($directory.'/maintainer_secrets.json');

        expect(json_decode($configuration, true))
            ->toBe(defaultMaintainerConfigurationFixture())
            ->and($configuration)->toContain(implode("\n", [
                '{',
                '    "ai": {',
                '        "providers": {',
            ]))
            ->and($directory.'/maintainer_secrets.json')->toBeFile()
            ->and($secrets)->toContain(implode("\n", [
                '{',
                '    "ai_providers": {',
                '        "anthropic": {',
            ]))
            ->and(file_get_contents($directory.'/.gitignore'))
            ->toBe("maintainer_secrets.json\n");
    });
});

it('creates the configuration in the project root when run from vendor bin', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')->assertSuccessful();

        expect($files->exists($directory.'/maintainer.json'))->toBeTrue()
            ->and($files->exists($directory.'/vendor/bin/maintainer.json'))->toBeFalse();
    }, workingDirectory: 'vendor/bin');
});

it('finds the project root from a nested directory without Composer proxy metadata', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('init')->assertSuccessful();

        expect($files->exists($directory.'/maintainer.json'))->toBeTrue()
            ->and($files->exists($directory.'/packages/example/maintainer.json'))->toBeFalse();
    }, workingDirectory: 'packages/example', exposeComposerProxy: false);
});

it('fails when run outside a Composer project', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->delete($directory.'/composer.json');

        $this->artisan('init')
            ->expectsOutputToContain('Unable to locate the project root.')
            ->assertFailed();

        expect($files->exists($directory.'/maintainer.json'))->toBeFalse();
    }, exposeComposerProxy: false);
});

it('does not overwrite an existing configuration file', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/maintainer.json';
        $files->put($path, "{\n    \"existing\": true\n}\n");

        $this->artisan('init')
            ->expectsOutputToContain('maintainer.json already exists.')
            ->assertFailed();

        expect($files->get($path))->toBe("{\n    \"existing\": true\n}\n");
    });
});

it('overwrites an existing configuration file when forced', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $path = $directory.'/maintainer.json';
        $files->put($path, "{\n    \"existing\": true\n}\n");

        $secretsPath = $directory.'/maintainer_secrets.json';
        $files->put($secretsPath, "{\"ai_providers\": {\"openai\": {\"key\": \"keep-me\"}}}\n");

        $this->artisan('init', ['--force' => true])
            ->expectsOutputToContain('maintainer_secrets.json already exists and was not overwritten.')
            ->assertSuccessful();

        expect(json_decode($files->get($path), true))->toBe(defaultMaintainerConfigurationFixture())
            ->and($files->get($secretsPath))->toContain('keep-me');
    });
});

it('adds the secrets file to an existing gitignore only once', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/.gitignore', "/vendor\n");

        $this->artisan('init')->assertSuccessful();
        $this->artisan('init', ['--force' => true])->assertSuccessful();

        expect($files->get($directory.'/.gitignore'))->toBe(implode("\n", [
            '/vendor',
            'maintainer_secrets.json',
            '',
        ]));
    });
});

it('publishes every Laravel AI provider in the secrets template', function () {
    withinTemporaryProject(function (string $directory) {
        $this->artisan('init')->assertSuccessful();

        $secrets = json_decode(file_get_contents($directory.'/maintainer_secrets.json'), true);

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

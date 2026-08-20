<?php

use App\Support\Ai\ConfiguredAiProvider;
use Illuminate\Filesystem\Filesystem;

it('loads the configured text provider credentials from the secrets file', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => [
                'providers' => [
                    'commit_message' => 'openai',
                ],
            ],
        ]);
        putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
            'ai_providers' => [
                'openai' => [
                    'key' => 'secret-key',
                    'url' => 'https://example.test/v1',
                ],
            ],
        ]);

        $provider = resolve(ConfiguredAiProvider::class)->for('commit_message');

        expect($provider)->toBe('openai')
            ->and(config('ai.providers.openai.key'))->toBe('secret-key')
            ->and(config('ai.providers.openai.url'))->toBe('https://example.test/v1');
    });
});

it('rejects providers that do not support text generation', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => [
                'providers' => [
                    'commit_message' => 'cohere',
                ],
            ],
        ]);

        expect(fn () => resolve(ConfiguredAiProvider::class)->for('commit_message'))
            ->toThrow(RuntimeException::class, 'cohere AI provider configured for commit_message does not support text generation');
    });
});

it('requires the protected secrets file before using AI', function () {
    withinTemporaryProject(function () {
        expect(fn () => resolve(ConfiguredAiProvider::class)->for('commit_message'))
            ->toThrow(RuntimeException::class, 'config/dev_maintainer_secrets.php is missing');
    });
});

it('continues reading legacy JSON secrets during migration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer_secrets.json', <<<'JSON'
            {
                "ai_providers": {
                    "openai": {
                        "key": "legacy-secret"
                    }
                }
            }
            JSON.PHP_EOL);

        resolve(ConfiguredAiProvider::class)->for('commit_message');

        expect(config('ai.providers.openai.key'))->toBe('legacy-secret');
    });
});

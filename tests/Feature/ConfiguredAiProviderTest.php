<?php

use App\Support\Ai\ConfiguredAiProvider;
use Illuminate\Filesystem\Filesystem;

it('loads the configured text provider credentials from the secrets file', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "ai": {
                    "providers": {
                        "commit_message": "openai"
                    }
                }
            }
            JSON.PHP_EOL);
        $files->put($directory.'/maintainer_secrets.json', <<<'JSON'
            {
                "ai_providers": {
                    "openai": {
                        "key": "secret-key",
                        "url": "https://example.test/v1"
                    }
                }
            }
            JSON.PHP_EOL);

        $provider = resolve(ConfiguredAiProvider::class)->for('commit_message');

        expect($provider)->toBe('openai')
            ->and(config('ai.providers.openai.key'))->toBe('secret-key')
            ->and(config('ai.providers.openai.url'))->toBe('https://example.test/v1');
    });
});

it('rejects providers that do not support text generation', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/maintainer.json', <<<'JSON'
            {
                "ai": {
                    "providers": {
                        "commit_message": "cohere"
                    }
                }
            }
            JSON.PHP_EOL);

        expect(fn () => resolve(ConfiguredAiProvider::class)->for('commit_message'))
            ->toThrow(RuntimeException::class, 'cohere AI provider configured for commit_message does not support text generation');
    });
});

it('requires the protected secrets file before using AI', function () {
    withinTemporaryProject(function () {
        expect(fn () => resolve(ConfiguredAiProvider::class)->for('commit_message'))
            ->toThrow(RuntimeException::class, 'maintainer_secrets.json is missing');
    });
});

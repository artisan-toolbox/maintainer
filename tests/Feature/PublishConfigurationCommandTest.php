<?php

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Illuminate\Filesystem\Filesystem;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\TextPrompt;

const PUBLISHABLE_CONFIGURATION_OPTIONS = [
    'maintainer' => 'Maintainer settings (config/dev_maintainer.php)',
    'maintainer-secrets' => 'Maintainer secrets (config/dev_maintainer_secrets.php)',
    'pint' => 'Pint (pint.json)',
    'rector' => 'Rector (rector.php)',
    'phpstan' => 'PHPStan (phpstan.neon)',
    'pest' => 'Pest / PHPUnit (phpunit.xml)',
    'deployer' => 'Deployer (deploy.php)',
];

beforeEach(function () {
    MultiSelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackWhen(true);
    TextPrompt::fallbackWhen(true);
});

it('requires interactive input for configuration selection and overwrite protection', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('config:publish', ['--no-interaction' => true])
            ->expectsOutputToContain('requires interactive input')
            ->assertFailed();

        expect($files->exists($directory.'/pint.json'))->toBeFalse();
    });
});

it('publishes only selected configuration files and ignores them by default', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['pint', 'deployer'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'yes')
            ->expectsOutputToContain('Configuration publishing completed.')
            ->assertSuccessful();

        expect($files->get($directory.'/pint.json'))->toContain('"preset": "laravel"')
            ->and($files->get($directory.'/deploy.php'))->toContain("require 'recipe/laravel.php';")
            ->and($files->exists($directory.'/rector.php'))->toBeFalse()
            ->and($files->get($directory.'/.gitignore'))->toBe(implode("\n", [
                'pint.json',
                'deploy.php',
                '',
            ]));
    });
});

it('publishes Maintainer configuration with the development user prefix', function () {
    forgetTestEnvironmentVariable('APP_KEY');

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");

            $this->artisan('config:publish')
                ->expectsChoice(
                    'Which configuration files would you like to publish?',
                    ['maintainer', 'maintainer-secrets'],
                    PUBLISHABLE_CONFIGURATION_OPTIONS,
                )
                ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'yes')
                ->expectsQuestion('Which email should identify the Maintainer SSH key?', 'developer@example.com')
                ->assertSuccessful();

            $secrets = require $directory.'/config/dev_maintainer_secrets.php';
            $keys = resolve(MaintainerSshKeys::class);

            expect(require $directory.'/config/dev_maintainer.php')
                ->toBe(defaultMaintainerConfigurationFixture())
                ->and($files->get($directory.'/config/dev_maintainer.php'))
                ->toContain("env('MAINTAINER_GIT_DIFF_OUTPUT_FORMAT', 'line_by_line')")
                ->and($secrets)->toHaveKey('key', env('APP_KEY'))
                ->toHaveKey('ai_providers.openai.key')
                ->and($secrets['ssh_key'])->toBeString()
                ->not->toContain('OPENSSH PRIVATE KEY')
                ->and($keys->privateKey())->toStartWith('-----BEGIN OPENSSH PRIVATE KEY-----')
                ->and($keys->publicKey())->toStartWith('ssh-ed25519 ')
                ->toEndWith(' developer@example.com')
                ->and($files->get($directory.'/config/dev_maintainer_secrets.php'))
                ->toMatch("/'key' => env\\('APP_KEY'\\),\n    'ssh_key' => '[^']+',\n    'ai_providers' => \\[/")
                ->toContain("env('OPENAI_API_KEY', '')")
                ->and($files->get($directory.'/.gitignore'))->toBe(implode("\n", [
                    'config/dev_maintainer.php',
                    'config/dev_maintainer_secrets.php',
                    '',
                ]));
        });
    } finally {
        forgetTestEnvironmentVariable('APP_KEY');
    }
});

it('publishes unprefixed Maintainer configuration in the production build environment', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->app['env'] = 'production';
        $options = PUBLISHABLE_CONFIGURATION_OPTIONS;
        $options['maintainer'] = 'Maintainer settings (config/maintainer.php)';
        $options['maintainer-secrets'] = 'Maintainer secrets (config/maintainer_secrets.php)';

        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['maintainer'],
                $options,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'no')
            ->assertSuccessful();

        expect($files->exists($directory.'/config/maintainer.php'))->toBeTrue()
            ->and($files->exists($directory.'/config/dev_maintainer.php'))->toBeFalse();
    });
});

it('requires an encryption key before publishing Maintainer secrets', function () {
    forgetTestEnvironmentVariable('APP_KEY');

    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['maintainer-secrets'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'no')
            ->expectsQuestion('Which email should identify the Maintainer SSH key?', 'developer@example.com')
            ->expectsOutputToContain('No Maintainer encryption key has been specified')
            ->assertFailed();

        expect($files->exists($directory.'/config/dev_maintainer_secrets.php'))->toBeFalse();
    });
});

it('does not request an email or rotate a key when secrets overwrite is declined', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
            'ssh_key' => 'keep-encrypted-key',
        ]);

        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['maintainer-secrets'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'no')
            ->expectsConfirmation('ARE YOU SURE you want to overwrite config/dev_maintainer_secrets.php?', 'no')
            ->assertSuccessful();

        expect(require $directory.'/config/dev_maintainer_secrets.php')
            ->toHaveKey('ssh_key', 'keep-encrypted-key');
    });
});

it('uses one project type selection for every project-specific template', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['rector', 'phpstan', 'pest'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsChoice(
                'Which project type should the selected configuration files target?',
                'laravel-package',
                [
                    'laravel-application' => 'Laravel application',
                    'laravel-package' => 'Laravel package',
                ],
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'no')
            ->assertSuccessful();

        expect($files->get($directory.'/rector.php'))->toContain("__DIR__.'/src'")
            ->and($files->get($directory.'/phpstan.neon'))->toContain('- src/')
            ->and($files->get($directory.'/phpunit.xml'))->toContain('<directory suffix=".php">src</directory>')
            ->and($files->exists($directory.'/.gitignore'))->toBeFalse();
    });
});

it('keeps an existing file unless the explicit overwrite warning is confirmed', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/pint.json', "custom configuration\n");

        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['pint'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'yes')
            ->expectsConfirmation('ARE YOU SURE you want to overwrite pint.json?', 'no')
            ->expectsOutputToContain('Kept existing pint.json; it was not overwritten.')
            ->assertSuccessful();

        expect($files->get($directory.'/pint.json'))->toBe("custom configuration\n")
            ->and($files->get($directory.'/.gitignore'))->toBe("pint.json\n");
    });
});

it('overwrites only the existing file whose warning is confirmed', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/pint.json', "custom configuration\n");

        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['pint'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'no')
            ->expectsConfirmation('ARE YOU SURE you want to overwrite pint.json?', 'yes')
            ->assertSuccessful();

        expect($files->get($directory.'/pint.json'))->toContain('"preset": "laravel"')
            ->and($files->exists($directory.'/.gitignore'))->toBeFalse();
    });
});

it('adds only missing gitignore entries without duplicating existing ones', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        $files->put($directory.'/.gitignore', "/vendor\npint.json\n");

        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['pint', 'deployer'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'yes')
            ->assertSuccessful();

        $this->artisan('config:publish')
            ->expectsChoice(
                'Which configuration files would you like to publish?',
                ['pint', 'deployer'],
                PUBLISHABLE_CONFIGURATION_OPTIONS,
            )
            ->expectsConfirmation('Add the selected configuration files to .gitignore?', 'yes')
            ->expectsConfirmation('ARE YOU SURE you want to overwrite pint.json?', 'no')
            ->expectsConfirmation('ARE YOU SURE you want to overwrite deploy.php?', 'no')
            ->assertSuccessful();

        expect($files->get($directory.'/.gitignore'))->toBe(implode("\n", [
            '/vendor',
            'pint.json',
            'deploy.php',
            '',
        ]));
    });
});

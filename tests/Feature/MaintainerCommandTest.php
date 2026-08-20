<?php

use App\Commands\MaintainerCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Laravel\Prompts\MultiSelectPrompt;
use Laravel\Prompts\SelectPrompt;

beforeEach(function () {
    MultiSelectPrompt::fallbackWhen(true);
    SelectPrompt::fallbackWhen(true);
});

/**
 * @return array<string, string>
 */
function maintainerMenuOptions(string $constant): array
{
    $options = new ReflectionClass(MaintainerCommand::class)->getConstant($constant);

    throw_unless(is_array($options), RuntimeException::class, "Maintainer menu constant {$constant} must contain an array.");

    foreach ($options as $command => $label) {
        throw_if(! is_string($command) || ! is_string($label), RuntimeException::class, "Maintainer menu constant {$constant} must map command names to labels.");
    }

    return $options;
}

function maintainerMenuBackSignal(): string
{
    $signal = new ReflectionClass(MaintainerCommand::class)->getConstant('BACK_SIGNAL');

    throw_unless(is_string($signal), RuntimeException::class, 'The Maintainer menu back signal must be a string.');

    return $signal;
}

function installMaintainerMenuQualityBinaries(string $directory, Filesystem $files): void
{
    foreach (['pint', 'pest'] as $binary) {
        $windows = PHP_OS_FAMILY === 'Windows';
        $path = $directory.'/vendor/bin/'.$binary.($windows ? '.bat' : '');
        $script = $windows
            ? "@echo off\r\nexit /b 0\r\n"
            : "#!/bin/sh\nexit 0\n";

        $files->put($path, $script);
        chmod($path, 0755);
    }
}

it('registers the Maintainer workflow menu as the default command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('maintainer')
        ->not->toHaveKey('init')
        ->and($commands['maintainer']->getDescription())
        ->toBe('Open the Maintainer workflow menu')
        ->and(config('commands.default'))
        ->toBe(MaintainerCommand::class);
});

it('groups the available maintenance workflows into sections', function () {
    expect(array_keys(maintainerMenuOptions('SECTIONS')))->toBe([
        'ci',
        'configuration',
        'deployment',
        'versioning',
        'exit',
    ])->and(array_keys(maintainerMenuOptions('VERSIONING_WORKFLOWS')))->toBe([
        'commit',
        'diff:html',
        'release:create',
    ])->and(array_keys(maintainerMenuOptions('CONFIGURATION_WORKFLOWS')))->toBe([
        'config:publish',
        'ssh:key',
        'ssh:public',
    ])->and(array_keys(maintainerMenuOptions('CI_WORKFLOWS')))->toBe([
        'pint',
        'rector',
        'phpstan',
        'pest',
    ])->and(array_keys(maintainerMenuOptions('DEPLOYMENT_WORKFLOWS')))->toBe([
        'deploy',
    ]);
});

it('returns from a cancelled submenu to the main menu', function () {
    $sections = maintainerMenuOptions('SECTIONS');

    $this->artisan('maintainer')
        ->expectsChoice('What would you like to manage?', 'versioning', $sections)
        ->expectsChoice('Choose a versioning workflow', maintainerMenuBackSignal(), maintainerMenuOptions('VERSIONING_WORKFLOWS'))
        ->expectsChoice('What would you like to manage?', 'exit', $sections)
        ->expectsOutputToContain('Main Menu › Versioning')
        ->assertSuccessful();
});

it('runs the CI tools selected in the submenu', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        installMaintainerMenuQualityBinaries($directory, $files);
        $files->put($directory.'/pint.json', "{}\n");
        $files->put($directory.'/phpunit.xml', "<phpunit/>\n");

        $this->artisan('maintainer')
            ->expectsChoice('What would you like to manage?', 'ci', maintainerMenuOptions('SECTIONS'))
            ->expectsChoice('Choose CI tools to run', ['pint', 'pest'], maintainerMenuOptions('CI_WORKFLOWS'))
            ->expectsOutputToContain('Main Menu › CI')
            ->expectsOutputToContain('Pint and Pest completed successfully.')
            ->assertSuccessful();

        $this->assertCommandCalled('quality', ['--tool' => ['pint', 'pest']]);
    });
});

it('rejects non-interactive execution and explains how to run a workflow directly', function () {
    $this->artisan('maintainer', ['--no-interaction' => true])
        ->expectsOutputToContain('The maintainer command requires interactive input.')
        ->assertFailed();

    $this->assertCommandNotCalled('release:create');
});

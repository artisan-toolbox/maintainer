<?php

use App\Commands\MaintainerCommand;
use Illuminate\Contracts\Console\Kernel;

it('registers the Maintainer workflow menu as the default command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('maintainer')
        ->and($commands['maintainer']->getDescription())
        ->toBe('Open the Maintainer workflow menu')
        ->and(config('commands.default'))
        ->toBe(MaintainerCommand::class);
});

it('offers the available maintenance workflows', function () {
    $workflows = new ReflectionClass(MaintainerCommand::class)->getConstant('WORKFLOWS');

    expect($workflows)->toBe([
        'commit' => 'Create a Git commit',
        'quality' => 'Run Pint, Rector, PHPStan, and Pest',
        'config:publish' => 'Publish configuration files',
        'release:create' => 'Create a new GitHub release',
        'init' => 'Create the Maintainer configuration and secrets files',
        'diff:html' => 'View a Git diff in the browser',
    ]);
});

it('rejects non-interactive execution and explains how to run a workflow directly', function () {
    $this->artisan('maintainer', ['--no-interaction' => true])
        ->expectsOutputToContain('The maintainer command requires interactive input.')
        ->assertFailed();

    $this->assertCommandNotCalled('release:create');
    $this->assertCommandNotCalled('init');
});

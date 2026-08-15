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

it('offers configuration initialization and GitHub release workflows', function () {
    $workflows = new ReflectionClass(MaintainerCommand::class)->getConstant('WORKFLOWS');

    expect($workflows)->toBe([
        'release:create' => 'Create a new GitHub release',
        'init' => 'Create the Maintainer configuration file',
    ]);
});

it('runs the GitHub release command selected from the menu', function () {
    $this->artisan('maintainer', ['--no-interaction' => true])
        ->expectsOutputToContain('__  __')
        ->assertSuccessful();

    $this->assertCommandCalled('release:create');
});

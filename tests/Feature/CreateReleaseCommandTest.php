<?php

use Illuminate\Contracts\Console\Kernel;

it('registers the create release command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('release:create')
        ->and($commands['release:create']->getDescription())
        ->toBe('Create a new GitHub release for the project');
});

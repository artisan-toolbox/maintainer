<?php

use Illuminate\Contracts\Console\Kernel;

it('registers the create commit command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)
        ->toHaveKey('commit')
        ->and($commands['commit']->getDescription())
        ->toBe('Create a Git commit from selected project changes');
});

it('rejects non-interactive commit creation', function () {
    $this->artisan('commit', ['--no-interaction' => true])
        ->expectsOutputToContain('requires interactive input')
        ->assertFailed();
});

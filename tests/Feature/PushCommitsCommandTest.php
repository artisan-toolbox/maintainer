<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\SelectPrompt;
use Tests\Support\GitPushFixture;

beforeEach(function () {
    SelectPrompt::fallbackWhen(true);
    ConfirmPrompt::fallbackWhen(true);
});

it('registers the pending commit push command', function () {
    $commands = $this->app->make(Kernel::class)->all();

    expect($commands)->toHaveKey('push')
        ->and($commands['push']->getDescription())->toBe('Review and push pending Git commits to origin');
});

it('requires an explicit branch and approval for non-interactive pushes', function (array $options) {
    $this->artisan('push', ['--no-interaction' => true, ...$options])
        ->expectsOutputToContain('require --branch=<local-branch> and --yes')
        ->assertFailed();
})->with([[[]], [['--branch' => 'main']], [['--yes' => true]]]);

it('reviews pending commits in the selected branch and pushes only after confirmation', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $fixture = new GitPushFixture($directory);

        try {
            $tip = $fixture->commit('Ready for origin');

            $this->artisan('push')
                ->expectsChoice('Which branch would you like to push?', 'branch-0', ['branch-0' => 'main → origin/main · 1 pending commit(s)'])
                ->expectsOutputToContain('Ready for origin')
                ->expectsConfirmation('Push all reviewed commits to this origin branch?', 'yes')
                ->expectsOutputToContain('Pushed the reviewed commits to origin.')
                ->assertSuccessful();

            expect($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($tip);
        } finally {
            $fixture->cleanup();
        }
    });
});

it('cancels without changing remote refs', function () {
    withinTemporaryProject(function (string $directory): void {
        $fixture = new GitPushFixture($directory);

        try {
            $original = $fixture->git(['rev-parse', 'main'], $fixture->remote);
            $fixture->commit('Keep local');

            $this->artisan('push', ['--branch' => 'main', '--yes' => true])
                ->expectsConfirmation('Push all reviewed commits to this origin branch?', 'no')
                ->expectsOutputToContain('Push cancelled.')
                ->assertSuccessful();

            expect($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($original);
        } finally {
            $fixture->cleanup();
        }
    });
});

it('pushes a named branch non-interactively and then reports no pending commits', function () {
    withinTemporaryProject(function (string $directory): void {
        $fixture = new GitPushFixture($directory);

        try {
            $tip = $fixture->commit('CI selected commit');
            $arguments = ['--branch' => 'main', '--yes' => true, '--no-interaction' => true];

            $this->artisan('push', $arguments)
                ->expectsOutputToContain('Checking origin for pending commits...')
                ->expectsOutputToContain('CI selected commit')
                ->expectsOutputToContain('Pushed the reviewed commits to origin.')
                ->assertSuccessful();

            expect($fixture->git(['rev-parse', 'main'], $fixture->remote))->toBe($tip);

            $this->artisan('push', $arguments)
                ->expectsOutputToContain('There are no pending commits to push.')
                ->assertSuccessful();
        } finally {
            $fixture->cleanup();
        }
    });
});

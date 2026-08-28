<?php

use App\Support\Ai\CommitMessageGenerator;
use App\Support\BrowserLauncher;
use App\Support\Git\CommitMessageReviewer;
use App\Support\Git\GitFileSelector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\TextareaPrompt;
use Symfony\Component\Process\Process;
use Tests\Fakes\FakeBrowserLauncher;

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

it('lets the user edit an AI-generated commit message before committing', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/example.txt', "original\n");
        putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
            'ai_providers' => [
                'openai' => [
                    'key' => 'test-key',
                ],
            ],
        ]);

        foreach ([
            ['init', '--initial-branch=main'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Create fixture'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        $files->put($directory.'/example.txt', "updated\n");
        app()->instance(GitFileSelector::class, new GitFileSelector(
            static fn (MultiSearchPrompt $prompt): array => array_keys($prompt->values),
        ));
        $browser = new FakeBrowserLauncher;
        app()->instance(BrowserLauncher::class, $browser);
        app()->instance(FakeBrowserLauncher::class, $browser);
        app()->instance(CommitMessageReviewer::class, new CommitMessageReviewer(
            static function (TextareaPrompt $prompt): string {
                expect($prompt->label)->toBe('Review the generated commit message')
                    ->and($prompt->default)->toBe('fix: generated commit message');

                return 'fix: edited commit message';
            },
        ));
        app()->instance(CommitMessageGenerator::class, new class implements CommitMessageGenerator
        {
            public function generate(
                string $provider,
                string $status,
                string $diff,
                ?string $userContext = null,
            ): string {
                return 'fix: generated commit message';
            }
        });

        $this->artisan('commit')->assertSuccessful();

        $message = new Process(['git', 'log', '-1', '--pretty=%B'], $directory)->mustRun()->getOutput();

        expect(trim($message))->toBe('fix: edited commit message');
    });
});

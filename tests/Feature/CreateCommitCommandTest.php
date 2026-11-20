<?php

use App\Ai\Agents\CommitMessageAgent;
use App\Support\Git\CommitMessageMode;
use App\Support\Git\CommitMessageReviewer;
use App\Support\Git\CommitWorkflowPrompts;
use App\Support\Git\GitFileSelector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\MultiSearchPrompt;
use Laravel\Prompts\TextareaPrompt;
use Symfony\Component\Process\Process;

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
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['providers' => ['commit_message' => 'anthropic']],
        ]);
        putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
            'ai_providers' => [
                'anthropic' => [
                    'key' => 'test-key',
                ],
            ],
        ]);
        config()->set('ai.providers.anthropic.models.text', [
            'default' => 'anthropic-default-model',
            'cheapest' => 'anthropic-cheapest-model',
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
        app()->instance(CommitWorkflowPrompts::class, new CommitWorkflowPrompts(
            reviewDiff: static fn (): bool => false,
            messageMode: static fn (): CommitMessageMode => CommitMessageMode::Ai,
            pushCommit: static fn (): bool => false,
        ));
        app()->instance(CommitMessageReviewer::class, new CommitMessageReviewer(
            static function (TextareaPrompt $prompt): string {
                expect($prompt->label)->toBe('Review the generated commit message')
                    ->and($prompt->default)->toBe("fix(example): update the sample\n\n- fix: Replace the original sample text");

                return 'fix: edited commit message';
            },
        ));
        CommitMessageAgent::fake([[
            'type' => 'fix',
            'scope' => 'example',
            'title' => 'update the sample',
            'breaking_change' => null,
            'changes' => [['type' => 'fix', 'description' => 'Replace the original sample text']],
            'trailers' => [],
        ]]);

        $this->withoutMockingConsoleOutput();
        expect(Artisan::call('commit'))->toBe(0)
            ->and(Artisan::output())->toMatch('~Generating the commit message[^\n]*anthropic / anthropic-cheapest-model~');

        $message = new Process(['git', 'log', '-1', '--pretty=%B'], $directory)->mustRun()->getOutput();

        expect(trim($message))->toBe('fix: edited commit message');
    });
});

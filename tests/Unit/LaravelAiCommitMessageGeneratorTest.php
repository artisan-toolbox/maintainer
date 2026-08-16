<?php

use App\Ai\Agents\CommitMessageAgent;
use App\Support\Ai\LaravelAiCommitMessageGenerator;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

uses(TestCase::class);

it('always sends the selected status and diff to the commit message agent', function () {
    CommitMessageAgent::fake(['feat(quality): add the maintainer workflow']);

    $message = (new LaravelAiCommitMessageGenerator)->generate(
        provider: 'openai',
        status: 'M  app/Example.php',
        diff: 'diff --git a/app/Example.php b/app/Example.php',
    );

    expect($message)->toBe('feat(quality): add the maintainer workflow');

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'M  app/Example.php')
        && str_contains($prompt->prompt, 'diff --git a/app/Example.php b/app/Example.php')
        && str_contains($prompt->prompt, 'No additional user context was supplied.'));
});

it('adds user context to the same prompt as the diff', function () {
    CommitMessageAgent::fake(['fix(commit): preserve selected paths']);

    (new LaravelAiCommitMessageGenerator)->generate(
        provider: 'openai',
        status: 'M  app/Commit.php',
        diff: 'selected diff',
        userContext: 'This fixes issue #42 without changing the public API.',
    );

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'This fixes issue #42')
        && str_contains($prompt->prompt, 'selected diff'));
});

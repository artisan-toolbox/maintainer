<?php

use App\Ai\Agents\CommitMessageAgent;
use App\Support\Ai\LaravelAiCommitMessageGenerator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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

it('bounds the prompt for oversized selected changes while retaining source details', function () {
    CommitMessageAgent::fake(['fix(commit): preserve source context']);

    $lockDiff = "diff --git a/composer.lock b/composer.lock\n".str_repeat("+generated dependency metadata\n", 10_000);
    $sourceDiff = "diff --git a/app/Commit.php b/app/Commit.php\n+return true;\n".str_repeat("+São Paulo\n", 10_000);
    $status = "M  composer.lock\nM  app/Commit.php\n".str_repeat("A  new-file.php\n", 10_000);
    $context = 'Keep issue #42 in view. '.str_repeat('ã', 25_000);

    (new LaravelAiCommitMessageGenerator)->generate('openai', $status, $lockDiff.$sourceDiff, $context);

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => strlen($prompt->prompt) < 33_000
        && mb_check_encoding($prompt->prompt, 'UTF-8')
        && str_contains($prompt->prompt, 'Keep issue #42 in view.')
        && str_contains($prompt->prompt, 'M  composer.lock')
        && str_contains($prompt->prompt, '+return true;')
        && str_contains($prompt->prompt, '[Additional diff content omitted.]')
        && ! str_contains($prompt->prompt, '+generated dependency metadata'));
});

it('retries once with a smaller prompt when the provider rejects the context size', function () {
    $prompts = [];
    CommitMessageAgent::fake(function (string $prompt) use (&$prompts): string {
        $prompts[] = $prompt;

        throw_if(count($prompts) === 1, RequestException::class, new Response(new PsrResponse(400, [], '{"error":{"message":"Your input exceeds the context window of this model.","code":"context_length_exceeded"}}')));

        return 'fix(commit): fit the provider context window';
    });

    $message = (new LaravelAiCommitMessageGenerator)->generate('openai', 'M  app/Commit.php', str_repeat("+selected diff\n", 900));

    expect($message)->toBe('fix(commit): fit the provider context window')
        ->and($prompts)->toHaveCount(2)
        ->and(strlen($prompts[1]))->toBeLessThan(strlen($prompts[0]))
        ->and($prompts[1])->toContain('M  app/Commit.php', '[Additional diff content omitted.]');
});

<?php

use App\Ai\Agents\ReleaseDiffSummaryAgent;
use App\Support\Ai\LaravelAiReleaseChangeAnalyzer;
use App\Support\Ai\ReleaseDiffChunker;
use App\Support\Release\ReleaseChangeSet;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

uses(TestCase::class);

it('always delegates diff summary model selection to the cheapest model attribute', function () {
    $attributes = new ReflectionClass(ReleaseDiffSummaryAgent::class)->getAttributes(UseCheapestModel::class);

    expect($attributes)->toHaveCount(1);
});

it('summarizes bounded diff fragments into reusable release context', function () {
    ReleaseDiffSummaryAgent::fake([
        ['summary' => 'Adds the first public release behavior.'],
        ['summary' => 'Documents the second release behavior.'],
    ]);
    $changes = new ReleaseChangeSet(
        "diff --git a/src/Feature.php b/src/Feature.php\n".str_repeat("+feature behavior\n", 8),
        'abc1234 Add release behavior',
    );

    $analyzed = new LaravelAiReleaseChangeAnalyzer(
        new ReleaseDiffChunker(maxCharacters: 120, maxChunks: 2),
    )->analyze('openai', $changes);

    expect($analyzed->diff)
        ->toContain('Fragment 1: Adds the first public release behavior.')
        ->toContain('Fragment 2: Documents the second release behavior.')
        ->not->toContain('+feature behavior')
        ->and($analyzed->commits)->toBe('abc1234 Add release behavior');

    ReleaseDiffSummaryAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'GIT DIFF FRAGMENT'));
});

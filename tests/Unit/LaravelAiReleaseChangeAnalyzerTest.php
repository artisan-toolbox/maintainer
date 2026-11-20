<?php

use App\Ai\Agents\ReleaseDiffSummaryAgent;
use App\Support\Ai\AiConsoleProgress;
use App\Support\Ai\AiReportLocale;
use App\Support\Ai\ConfiguredAiModel;
use App\Support\Ai\LaravelAiReleaseChangeAnalyzer;
use App\Support\Ai\ReleaseDiffChunker;
use App\Support\Release\ReleaseChangeSet;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

uses(TestCase::class);

it('uses the other provider cheapest model when no override is configured', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['models' => ['release_changelog_update' => null]],
        ]);
        config()->set('ai.providers.anthropic.models.text', [
            'default' => 'anthropic-default-model',
            'cheapest' => 'anthropic-cheapest-model',
        ]);
        ReleaseDiffSummaryAgent::fake([['summary' => 'Describes the changed fixture.']]);

        resolve(LaravelAiReleaseChangeAnalyzer::class)->analyze(
            'anthropic',
            new ReleaseChangeSet(
                "diff --git a/src/Feature.php b/src/Feature.php\n+feature behavior\n",
                'abc1234 fix: repair a feature',
            ),
        );

        ReleaseDiffSummaryAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'anthropic-cheapest-model');
    });
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
        resolve(ConfiguredAiModel::class),
        resolve(AiReportLocale::class),
        resolve(AiConsoleProgress::class),
    )->analyze('openai', $changes);

    expect($analyzed->diff)
        ->toContain('Fragment 1: Adds the first public release behavior.')
        ->toContain('Fragment 2: Documents the second release behavior.')
        ->not->toContain('+feature behavior')
        ->and($analyzed->commits)->toBe('abc1234 Add release behavior');

    ReleaseDiffSummaryAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'GIT DIFF FRAGMENT')
        && $prompt->model === 'gpt-6.2-sol');
});

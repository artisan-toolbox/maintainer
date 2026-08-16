<?php

use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Ai\LaravelAiReleaseVersionRecommender;
use App\Support\Ai\ReleaseIncrement;
use App\Support\Diff\GitDiffGenerator;
use App\Support\Release\SemanticVersionNumber;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

it('always delegates model selection to the cheapest model attribute', function () {
    $attributes = new ReflectionClass(ReleaseVersionAgent::class)->getAttributes(UseCheapestModel::class);

    expect($attributes)->toHaveCount(1);
});

it('returns a typed structured recommendation based on the release diff', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        foreach ([
            ['init', '--initial-branch=1.x'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Initial release'],
            ['tag', '1.0.0'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        $files->put($directory.'/feature.txt', "A backward-compatible feature.\n");
        new Process(['git', 'add', '.'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Add feature'], $directory)->mustRun();

        ReleaseVersionAgent::fake([[
            'release_increment' => 'minor',
            'justification' => 'The diff introduces backward-compatible functionality.',
        ]]);

        $recommendation = new LaravelAiReleaseVersionRecommender(new GitDiffGenerator)->recommend(
            'openai',
            $directory,
            new SemanticVersionNumber(1, 0, 0),
        );

        expect($recommendation->increment)->toBe(ReleaseIncrement::Minor)
            ->and($recommendation->justification)->toBe('The diff introduces backward-compatible functionality.');

        ReleaseVersionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Recommend the next stable release increment')
            && str_contains($prompt->prompt, 'diff --git')
            && str_contains($prompt->prompt, 'feature.txt'));
    });
});

it('rejects a structured recommendation outside the supported release increments', function () {
    withinTemporaryProject(function (string $directory): void {
        foreach ([
            ['init', '--initial-branch=1.x'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Initial release'],
            ['tag', '1.0.0'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        ReleaseVersionAgent::fake([[
            'release_increment' => 'major',
            'justification' => 'An unsupported recommendation.',
        ]]);

        expect(fn () => new LaravelAiReleaseVersionRecommender(new GitDiffGenerator)->recommend(
            'openai',
            $directory,
            new SemanticVersionNumber(1, 0, 0),
        ))->toThrow(RuntimeException::class, 'must recommend either patch or minor');
    });
});

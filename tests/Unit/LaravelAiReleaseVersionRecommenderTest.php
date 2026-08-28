<?php

use App\Ai\Agents\ReleaseVersionAgent;
use App\Support\Ai\LaravelAiReleaseVersionRecommender;
use App\Support\Ai\ReleaseDiffChunker;
use App\Support\Ai\ReleaseIncrement;
use App\Support\Diff\GitDiffGenerator;
use App\Support\Quality\LaravelProjectTypeDetector;
use App\Support\Release\SemanticVersionNumber;
use Illuminate\Filesystem\Filesystem;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

function releaseVersionRecommender(?ReleaseDiffChunker $chunker = null): LaravelAiReleaseVersionRecommender
{
    return new LaravelAiReleaseVersionRecommender(
        new GitDiffGenerator,
        $chunker ?? new ReleaseDiffChunker,
        new LaravelProjectTypeDetector(new Filesystem),
    );
}

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

        $recommendation = releaseVersionRecommender()->recommend(
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

        expect(fn () => releaseVersionRecommender()->recommend(
            'openai',
            $directory,
            new SemanticVersionNumber(1, 0, 0),
        ))->toThrow(RuntimeException::class, 'must recommend either patch or minor');
    });
});

it('selects minor when any bounded diff fragment adds public functionality', function () {
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

        $files->put($directory.'/fix.txt', "A backward-compatible fix.\n");
        $files->put($directory.'/feature.txt', "A backward-compatible feature.\n");
        new Process(['git', 'add', '.'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Fix and extend behavior'], $directory)->mustRun();

        ReleaseVersionAgent::fake([
            [
                'release_increment' => 'minor',
                'justification' => 'This fragment adds public functionality.',
            ],
            [
                'release_increment' => 'patch',
                'justification' => 'This fragment only fixes existing behavior.',
            ],
        ]);

        $recommendation = releaseVersionRecommender()
            ->recommend('openai', $directory, new SemanticVersionNumber(1, 0, 0));

        expect($recommendation->increment)->toBe(ReleaseIncrement::Minor)
            ->and($recommendation->justification)->toBe('This fragment adds public functionality.');
    });
});

it('excludes development AI files from Laravel application recommendations', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/composer.json', "{\"type\": \"project\"}\n");

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

        $files->ensureDirectoryExists($directory.'/.ai/rules');
        $files->ensureDirectoryExists($directory.'/app');
        $files->put($directory.'/.ai/rules/versioning.md', "Recommend minor releases for new MCP tools.\n");
        $files->put($directory.'/.mcp.json', "{\"servers\": {}}\n");
        $files->put($directory.'/AGENTS.md', "Development instructions.\n");
        $files->put($directory.'/app/Feature.php', "<?php\n\nfinal class Feature {}\n");
        new Process(['git', 'add', '.'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Fix application behavior'], $directory)->mustRun();

        ReleaseVersionAgent::fake([[
            'release_increment' => 'patch',
            'justification' => 'The application change fixes existing behavior.',
        ]]);

        $recommendation = releaseVersionRecommender()
            ->recommend('openai', $directory, new SemanticVersionNumber(1, 0, 0));

        expect($recommendation->increment)->toBe(ReleaseIncrement::Patch);
        ReleaseVersionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'app/Feature.php')
            && str_contains($prompt->prompt, 'Development-only AI tooling')
            && ! str_contains($prompt->prompt, '.ai/rules/versioning.md')
            && ! str_contains($prompt->prompt, '.mcp.json')
            && ! str_contains($prompt->prompt, 'AGENTS.md'));
    });
});

it('defaults Laravel applications to patch when only development AI files changed', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/composer.json', "{\"type\": \"project\"}\n");

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

        $files->ensureDirectoryExists($directory.'/.cursor/rules');
        $files->put($directory.'/.cursor/rules/releases.mdc', "Recommend minor releases.\n");
        new Process(['git', 'add', '.'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Update development AI rules'], $directory)->mustRun();
        ReleaseVersionAgent::fake();

        $recommendation = releaseVersionRecommender()
            ->recommend('openai', $directory, new SemanticVersionNumber(1, 0, 0));

        expect($recommendation->increment)->toBe(ReleaseIncrement::Patch)
            ->and($recommendation->justification)->toContain('development AI support');
        ReleaseVersionAgent::assertNeverPrompted();
    });
});

it('includes development AI files in Laravel package recommendations', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $files->put($directory.'/composer.json', "{\"type\": \"library\"}\n");

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

        $files->ensureDirectoryExists($directory.'/.ai/rules');
        $files->put($directory.'/.ai/rules/versioning.md', "Add package development guidance.\n");
        new Process(['git', 'add', '.'], $directory)->mustRun();
        new Process(['git', 'commit', '-m', 'Add package AI guidance'], $directory)->mustRun();

        ReleaseVersionAgent::fake([[
            'release_increment' => 'minor',
            'justification' => 'The package adds reusable development guidance.',
        ]]);

        $recommendation = releaseVersionRecommender()
            ->recommend('openai', $directory, new SemanticVersionNumber(1, 0, 0));

        expect($recommendation->increment)->toBe(ReleaseIncrement::Minor);
        ReleaseVersionAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '.ai/rules/versioning.md')
            && str_contains($prompt->prompt, 'Developer-facing capabilities'));
    });
});

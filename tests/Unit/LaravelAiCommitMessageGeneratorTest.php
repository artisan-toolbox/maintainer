<?php

use App\Ai\Agents\CommitMessageAgent;
use App\Support\Ai\LaravelAiCommitMessageGenerator;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

uses(TestCase::class);

function commitMessageFields(string $type = 'fix', ?string $scope = 'commit', string $title = 'preserve selected paths'): array
{
    return [
        'type' => $type,
        'scope' => $scope,
        'title' => $title,
        'breaking_change' => null,
        'changes' => [],
        'trailers' => [],
    ];
}

it('always sends the selected status and diff to the commit message agent', function () {
    CommitMessageAgent::fake([commitMessageFields('feat', 'quality', 'add the maintainer workflow')]);

    $message = resolve(LaravelAiCommitMessageGenerator::class)->generate(
        provider: 'openai',
        status: 'M  app/Example.php',
        diff: 'diff --git a/app/Example.php b/app/Example.php',
    );

    expect($message)->toBe('feat(quality): add the maintainer workflow');

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'M  app/Example.php')
        && str_contains($prompt->prompt, 'diff --git a/app/Example.php b/app/Example.php')
        && str_contains($prompt->prompt, 'No additional user context was supplied.')
        && str_contains($prompt->prompt, 'project locale en.')
        && $prompt->model === 'gpt-6.2-sol');
});

it('adds user context to the same prompt as the diff', function () {
    CommitMessageAgent::fake([commitMessageFields()]);

    resolve(LaravelAiCommitMessageGenerator::class)->generate(
        provider: 'openai',
        status: 'M  app/Commit.php',
        diff: 'selected diff',
        userContext: 'This fixes issue #42 without changing the public API.',
    );

    CommitMessageAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'This fixes issue #42')
        && str_contains($prompt->prompt, 'selected diff'));
});

it('bounds the prompt for oversized selected changes while retaining source details', function () {
    CommitMessageAgent::fake([commitMessageFields(title: 'preserve source context')]);

    $lockDiff = "diff --git a/composer.lock b/composer.lock\n".str_repeat("+generated dependency metadata\n", 10_000);
    $sourceDiff = "diff --git a/app/Commit.php b/app/Commit.php\n+return true;\n".str_repeat("+São Paulo\n", 10_000);
    $status = "M  composer.lock\nM  app/Commit.php\n".str_repeat("A  new-file.php\n", 10_000);
    $context = 'Keep issue #42 in view. '.str_repeat('ã', 25_000);

    resolve(LaravelAiCommitMessageGenerator::class)->generate('openai', $status, $lockDiff.$sourceDiff, $context);

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
    CommitMessageAgent::fake(function (string $prompt) use (&$prompts): array {
        $prompts[] = $prompt;

        throw_if(count($prompts) === 1, RequestException::class, new Response(new PsrResponse(400, [], '{"error":{"message":"Your input exceeds the context window of this model.","code":"context_length_exceeded"}}')));

        return commitMessageFields(title: 'fit the provider context window');
    });

    $message = resolve(LaravelAiCommitMessageGenerator::class)->generate('openai', 'M  app/Commit.php', str_repeat("+selected diff\n", 900));

    expect($message)->toBe('fix(commit): fit the provider context window')
        ->and($prompts)->toHaveCount(2)
        ->and(strlen($prompts[1]))->toBeLessThan(strlen($prompts[0]))
        ->and($prompts[1])->toContain('M  app/Commit.php', '[Additional diff content omitted.]');
});

it('renders scoped and breaking commits with typed changes and supplied Git trailers', function () {
    CommitMessageAgent::fake([array_replace(commitMessageFields('feat', 'quality', 'require configured checks.'), [
        'breaking_change' => 'Projects must configure every required tool.',
        'changes' => [
            ['type' => 'feat', 'description' => 'Require configured checks'],
            ['type' => 'fix', 'description' => 'Report skipped checks correctly'],
        ],
        'trailers' => [
            ['token' => 'Refs', 'value' => '#42'],
            ['token' => 'Closes', 'value' => '#42'],
            ['token' => 'Co-authored-by', 'value' => 'Dev <dev@example.test>'],
        ],
    ])]);

    $message = resolve(LaravelAiCommitMessageGenerator::class)->generate('openai', 'M  app/Commit.php', 'selected diff', "Refs: #42\nCo-authored-by: Dev <dev@example.test>");

    expect($message)->toBe(<<<'COMMIT'
        feat(quality)!: require configured checks

        - feat: Require configured checks
        - fix: Report skipped checks correctly

        BREAKING CHANGE: Projects must configure every required tool.
        Refs: #42
        Co-authored-by: Dev <dev@example.test>
        COMMIT);
});

it('uses the configured project locale and commit model for the structured API request', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => [
                'project_locale' => 'fr_CA',
                'local_feedback_locale' => 'pt_BR',
                'models' => ['commit_message' => 'configured-commit-model'],
            ],
        ]);
        config()->set('ai.providers.openai.key', 'test-key');
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'id' => 'resp_commit',
                'status' => 'completed',
                'model' => 'configured-commit-model',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(commitMessageFields(scope: null), JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);

        $message = resolve(LaravelAiCommitMessageGenerator::class)->generate('openai', 'M  app/Commit.php', 'selected diff');

        expect($message)->toBe('fix: preserve selected paths');
        Http::assertSent(function (Request $request): bool {
            $format = $request['text']['format'];
            $prompt = json_encode($request['input'], JSON_THROW_ON_ERROR);

            return $request['model'] === 'configured-commit-model'
                && $format['type'] === 'json_schema'
                && $format['strict'] === true
                && $format['schema']['required'] === ['type', 'scope', 'title', 'breaking_change', 'changes', 'trailers']
                && str_contains($prompt, 'project locale fr_CA')
                && ! str_contains($prompt, 'pt_BR');
        });
    });
});

it('keeps the formatted subject within the width limit', function () {
    CommitMessageAgent::fake([commitMessageFields(title: str_repeat('ç', 100))]);

    $message = resolve(LaravelAiCommitMessageGenerator::class)->generate('openai', 'M  app/Commit.php', 'selected diff');

    expect(mb_strlen($message))->toBe(72)->and($message)->toStartWith('fix(commit): ');
});

it('rejects malformed structured commit messages before Git sees them', function (array|string $fields) {
    CommitMessageAgent::fake([is_array($fields) ? array_replace(commitMessageFields(), $fields) : $fields]);

    expect(fn () => resolve(LaravelAiCommitMessageGenerator::class)->generate('openai', 'M  app/Commit.php', 'selected diff'))
        ->toThrow(RuntimeException::class);
})->with([
    'unsupported type' => [['type' => 'custom']],
    'invalid scope' => [['scope' => 'quality): duplicate']],
    'newline in title' => [['title' => "change behavior\n\nCloses: #34"]],
    'prefix in title' => [['title' => 'fix(quality): preserve selected paths']],
    'empty breaking change' => [['breaking_change' => '']],
    'malformed change' => [['changes' => ['fix: apply a check']]],
    'unsupported trailer' => [['trailers' => [['token' => 'Signed-off-by', 'value' => 'Bob']]]],
    'free-form response' => ['fix: preserve selected paths'],
]);

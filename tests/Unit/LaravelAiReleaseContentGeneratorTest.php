<?php

use App\Ai\Agents\ReleaseChangelogAgent;
use App\Ai\Agents\ReleaseNotesAgent;
use App\Support\Ai\LaravelAiReleaseChangelogGenerator;
use App\Support\Ai\LaravelAiReleaseNotesGenerator;
use App\Support\Release\ReleaseChangeSet;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

uses(TestCase::class);

it('uses the project locale and model overrides for release notes and changelog entries', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => [
                'local_feedback_locale' => 'pt_BR',
                'project_locale' => 'es_AR',
                'models' => [
                    'release_notes' => 'notes-model',
                    'release_changelog_update' => 'changelog-model',
                ],
            ],
        ]);
        ReleaseNotesAgent::fake([[
            'title' => 'Mejorar la configuración',
            'sections' => [[
                'type' => 'changed',
                'heading' => 'Cambios',
                'items' => ['Actualiza la configuración.'],
            ]],
        ]]);
        ReleaseChangelogAgent::fake([['entries' => [[
            'type' => 'fix',
            'hash' => 'abc1234',
            'title' => 'Mejorar la configuración',
            'description' => 'Actualiza la configuración.',
        ]]]]);
        $changes = new ReleaseChangeSet('Configuration fix.', 'abc1234 fix: update configuration');

        $notes = resolve(LaravelAiReleaseNotesGenerator::class)->generate('openai', '1.2.3', $changes);
        resolve(LaravelAiReleaseChangelogGenerator::class)->generate('openai', '1.2.3', $changes);

        expect($notes->title)->toBe('1.2.3 - Mejorar la configuración')
            ->and($notes->body)->toBe("## Cambios\n\n- Actualiza la configuración.");

        ReleaseNotesAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'notes-model'
            && str_contains($prompt->prompt, 'project locale es_AR')
            && ! str_contains($prompt->prompt, 'pt_BR'));
        ReleaseChangelogAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->model === 'changelog-model'
            && str_contains($prompt->prompt, 'project locale es_AR')
            && ! str_contains($prompt->prompt, 'pt_BR'));
    });
});

it('requests strict changelog output with the allowed commit types', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    Http::preventStrayRequests();
    Http::fake([
        '*' => Http::response([
            'id' => 'resp_changelog',
            'status' => 'completed',
            'model' => 'gpt-5-nano',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['entries' => [[
                        'type' => 'feat',
                        'hash' => 'abc1234',
                        'title' => 'Automate releases',
                        'description' => 'Adds the release workflow.',
                    ]]], JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);

    $entries = resolve(LaravelAiReleaseChangelogGenerator::class)->generate(
        'openai',
        '2.1.0',
        new ReleaseChangeSet('release summary', 'abc1234 feat: automate releases'),
    );

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe('feat');

    Http::assertSent(function (Request $request): bool {
        $format = $request['text']['format'];

        return $request['model'] === 'gpt-6.2-sol'
            && $format['type'] === 'json_schema'
            && $format['strict'] === true
            && $format['schema']['properties']['entries']['items']['properties']['type']['enum'] === [
                'feat', 'fix', 'docs', 'style', 'refactor', 'perf', 'test', 'build', 'ci', 'chore', 'revert',
            ];
    });
});

it('identifies the commit and invalid changelog type without accepting malformed output', function (mixed $type, string $received) {
    ReleaseChangelogAgent::fake([[
        'entries' => [[
            'type' => $type,
            'hash' => 'abc1234',
            'title' => 'Automate releases',
            'description' => 'Adds the release workflow.',
        ]],
    ]]);

    expect(fn () => resolve(LaravelAiReleaseChangelogGenerator::class)->generate(
        'openai',
        '2.1.0',
        new ReleaseChangeSet('release summary', 'abc1234 feat: automate releases'),
    ))->toThrow(RuntimeException::class, "The AI provider returned an invalid changelog type for commit abc1234: {$received}. Expected one of: feat, fix, docs, style, refactor, perf, test, build, ci, chore, revert.");
})->with([
    'unknown category' => ['feature', 'feature'],
    'missing category' => [null, 'null'],
    'non-string category' => [['feat'], 'array'],
]);

it('requests typed sections and renders release notes in category order', function () {
    config()->set('ai.providers.openai.key', 'test-key');
    Http::preventStrayRequests();
    $structuredNotes = [
        'title' => 'Complete GitHub release automation',
        'sections' => [
            ['type' => 'fixed', 'heading' => 'Bug fixes', 'items' => ['Preserve the repository after a failed release.']],
            ['type' => 'added', 'heading' => 'New feature', 'items' => ['Publish releases automatically.', 'Review the generated title.']],
        ],
    ];
    Http::fake([
        '*' => Http::response([
            'id' => 'resp_release_notes',
            'status' => 'completed',
            'model' => 'gpt-6.2-sol',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($structuredNotes, JSON_THROW_ON_ERROR),
                ]],
            ]],
        ]),
    ]);
    $changes = new ReleaseChangeSet('release diff', 'abc1234 Add release automation');

    $notes = resolve(LaravelAiReleaseNotesGenerator::class)->generate('openai', '2.1.0', $changes);

    expect($notes->title)->toBe('2.1.0 - Complete GitHub release automation')
        ->and($notes->body)->toBe(<<<'MARKDOWN'
            ## Added

            - Publish releases automatically.
            - Review the generated title.

            ## Fixed

            - Preserve the repository after a failed release.
            MARKDOWN);

    Http::assertSent(function (Request $request): bool {
        $format = $request['text']['format'];
        $section = $format['schema']['properties']['sections']['items'];
        $prompt = $request->body();

        return $request['model'] === 'gpt-6.2-sol'
            && $format['type'] === 'json_schema'
            && $format['strict'] === true
            && $section['properties']['type']['enum'] === [
                'added', 'changed', 'fixed', 'removed', 'performance', 'documentation', 'testing', 'maintenance',
            ]
            && $section['properties']['items']['items']['type'] === 'string'
            && str_contains($prompt, 'version 2.1.0')
            && str_contains($prompt, 'abc1234 Add release automation')
            && str_contains($prompt, 'release diff');
    });
});

it('normalizes an AI title that already contains the release tag', function () {
    ReleaseNotesAgent::fake([[
        'title' => 'v2.1.0 — Complete GitHub release automation',
        'sections' => [[
            'type' => 'added',
            'heading' => 'Added',
            'items' => ['Automated publishing with rollback protection.'],
        ]],
    ]]);

    $notes = resolve(LaravelAiReleaseNotesGenerator::class)->generate(
        'openai',
        '2.1.0',
        new ReleaseChangeSet('release diff', 'abc1234 Add release automation'),
    );

    expect($notes->title)->toBe('2.1.0 - Complete GitHub release automation');
});

it('rejects malformed release notes sections', function (array $sections) {
    ReleaseNotesAgent::fake([['title' => 'Automate publishing', 'sections' => $sections]]);

    expect(fn () => resolve(LaravelAiReleaseNotesGenerator::class)->generate(
        'openai',
        '2.1.0',
        new ReleaseChangeSet('release summary', 'abc1234 feat: automate publishing'),
    ))->toThrow(RuntimeException::class);
})->with([
    'empty sections' => [[]],
    'unknown type' => [[['type' => 'feature', 'heading' => 'New', 'items' => ['Publish releases.']]]],
    'duplicate sections' => [[
        ['type' => 'added', 'heading' => 'Added', 'items' => ['Publish releases.']],
        ['type' => 'added', 'heading' => 'Added', 'items' => ['Review releases.']],
    ]],
    'empty items' => [[['type' => 'added', 'heading' => 'Added', 'items' => []]]],
    'Markdown blocks in items' => [[['type' => 'added', 'heading' => 'Added', 'items' => ["Publish releases.\n## Injected"]]]],
]);

it('returns validated structured changelog entries', function () {
    ReleaseChangelogAgent::fake([[
        'entries' => [[
            'type' => 'feat',
            'hash' => 'abc1234',
            'title' => 'Automate releases',
            'description' => 'Updates project metadata, pushes the release commit, and publishes GitHub notes.',
        ]],
    ]]);
    $changes = new ReleaseChangeSet('release diff', 'abc1234 Add release automation');

    $entries = resolve(LaravelAiReleaseChangelogGenerator::class)->generate('openai', '2.1.0', $changes);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe('feat')
        ->and($entries[0]->hash)->toBe('abc1234')
        ->and($entries[0]->description)->toContain('publishes GitHub notes');
});

it('ignores uncommitted AI entries and creates deterministic entries for omitted commits', function () {
    ReleaseChangelogAgent::fake([[
        'entries' => [
            [
                'type' => 'chore',
                'hash' => '',
                'title' => 'Prepare generated release files',
                'description' => 'Updates the version and generated build before the release commit exists.',
            ],
            [
                'type' => 'feat',
                'hash' => 'abc1234',
                'title' => 'Add release automation',
                'description' => 'Adds the public release automation workflow.',
            ],
        ],
    ]]);
    $changes = new ReleaseChangeSet(
        'release summary',
        "abc1234\tfeat: add release automation\ndef5678\tfix(release): fetch missing tags",
    );

    $entries = resolve(LaravelAiReleaseChangelogGenerator::class)->generate('openai', '2.1.0', $changes);

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->hash)->toBe('abc1234')
        ->and($entries[1]->hash)->toBe('def5678')
        ->and($entries[1]->type)->toBe('fix')
        ->and($entries[1]->title)->toBe('Fetch missing tags')
        ->and(collect($entries)->pluck('hash'))->not->toContain('');
});

it('rejects release changelog generation when no source commits exist', function () {
    ReleaseChangelogAgent::fake();

    expect(fn () => resolve(LaravelAiReleaseChangelogGenerator::class)->generate(
        'openai',
        '2.1.0',
        new ReleaseChangeSet('release summary', ''),
    ))->toThrow(RuntimeException::class, 'No Git commits were found for the release changelog.');

    ReleaseChangelogAgent::assertNeverPrompted();
});

it('creates deterministic entries when AI returns an empty changelog', function () {
    ReleaseChangelogAgent::fake([['entries' => []]]);

    $entries = resolve(LaravelAiReleaseChangelogGenerator::class)->generate(
        'openai',
        '2.1.0',
        new ReleaseChangeSet('release summary', 'abc1234 feat: add release automation'),
    );

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->hash)->toBe('abc1234')
        ->and($entries[0]->type)->toBe('feat');
});

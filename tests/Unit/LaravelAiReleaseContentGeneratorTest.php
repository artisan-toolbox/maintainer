<?php

use App\Ai\Agents\ReleaseChangelogAgent;
use App\Ai\Agents\ReleaseNotesAgent;
use App\Support\Ai\LaravelAiReleaseChangelogGenerator;
use App\Support\Ai\LaravelAiReleaseNotesGenerator;
use App\Support\Release\ReleaseChangeSet;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\TestCase;

uses(TestCase::class);

it('returns structured GitHub release notes from the release changes', function () {
    ReleaseNotesAgent::fake([[
        'title' => 'Complete GitHub release automation',
        'body' => "## Added\n\nAutomated publishing with rollback protection.",
    ]]);
    $changes = new ReleaseChangeSet('release diff', 'abc1234 Add release automation');

    $notes = (new LaravelAiReleaseNotesGenerator)->generate('openai', '2.1.0', $changes);

    expect($notes->title)->toBe('Complete GitHub release automation')
        ->and($notes->body)->toContain('rollback protection');

    ReleaseNotesAgent::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'version 2.1.0')
        && str_contains($prompt->prompt, 'abc1234 Add release automation')
        && str_contains($prompt->prompt, 'release diff'));
});

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

    $entries = (new LaravelAiReleaseChangelogGenerator)->generate('openai', '2.1.0', $changes);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->type)->toBe('feat')
        ->and($entries[0]->hash)->toBe('abc1234')
        ->and($entries[0]->description)->toContain('publishes GitHub notes');
});

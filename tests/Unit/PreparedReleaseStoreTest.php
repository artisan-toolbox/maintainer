<?php

use App\Support\Release\GitHubReleaseWorkflow;
use App\Support\Release\PreparedRelease;
use App\Support\Release\PreparedReleaseStore;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('reads a prepared release recorded before multiple workflows were supported', function (): void {
    withinTemporaryProject(function (string $root, Filesystem $files): void {
        new Process(['git', 'init'], $root)->mustRun();
        $files->put($root.'/.git/maintainer-release.json', json_encode([
            'repository' => 'github.com/example/project',
            'workflow' => 'tests.yml',
            'branch' => '1.x',
            'commit' => str_repeat('a', 40),
            'currentVersion' => '1.0.0',
            'version' => '1.0.1',
            'title' => '1.0.1 - Fix a release check',
            'body' => 'Release notes.',
        ], flags: JSON_THROW_ON_ERROR));

        $release = new PreparedReleaseStore($files)->read($root);

        expect($release->requiresWorkflows([new GitHubReleaseWorkflow('tests.yml', 42)]))->toBeTrue()
            ->and($release->requiresWorkflows([new GitHubReleaseWorkflow('coverage.yml', 43)]))->toBeFalse();
    });
});

it('keeps the required workflow identities with a prepared release', function (): void {
    withinTemporaryProject(function (string $root, Filesystem $files): void {
        new Process(['git', 'init'], $root)->mustRun();
        $store = new PreparedReleaseStore($files);
        $release = new PreparedRelease(
            'github.com/example/project',
            [new GitHubReleaseWorkflow('tests.yml', 42), new GitHubReleaseWorkflow('coverage.yml', 43)],
            '1.x',
            str_repeat('a', 40),
            '1.0.0',
            '1.0.1',
            '1.0.1 - Fix a release check',
            'Release notes.',
        );

        $store->write($root, $release);

        $loaded = $store->read($root);
        expect($loaded)->toEqual($release)
            ->and($loaded->requiresWorkflows([new GitHubReleaseWorkflow('Coverage', 43), new GitHubReleaseWorkflow('Tests', 42)]))->toBeTrue()
            ->and($loaded->requiresWorkflows([new GitHubReleaseWorkflow('tests.yml', 42), new GitHubReleaseWorkflow('coverage.yml', 99)]))->toBeFalse()
            ->and($loaded->requiresWorkflows([new GitHubReleaseWorkflow('tests.yml', 42)]))->toBeFalse();
    });
});

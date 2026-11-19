<?php

use App\Support\Release\GitHubReleaseGate;
use App\Support\Release\PreparedRelease;
use Symfony\Component\Process\Process;

function preparedGitHubReleaseForGate(): PreparedRelease
{
    return new PreparedRelease(
        'github.com/example/project',
        'tests.yml',
        '1.x',
        str_repeat('a', 40),
        '1.0.0',
        '1.0.1',
        '1.0.1 - Fix a release check',
        'Release notes.',
    );
}

function processReturningGitHubRun(string $output): Process
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(1);
    $process->shouldReceive('run')->once()->andReturn(0);
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $process->shouldReceive('getOutput')->once()->andReturn($output);

    return $process;
}

it('waits for the latest push run for the prepared commit and configured workflow', function (): void {
    $release = preparedGitHubReleaseForGate();
    $received = [];
    $gate = new GitHubReleaseGate(function (array $command, string $root) use (&$received, $release): Process {
        $received = [$command, $root];

        return processReturningGitHubRun(json_encode([[
            'headSha' => $release->commit,
            'headBranch' => '1.x',
            'event' => 'push',
            'status' => 'completed',
            'conclusion' => 'success',
            'url' => 'https://github.com/example/project/actions/runs/1',
        ]], flags: JSON_THROW_ON_ERROR));
    });

    $gate->waitForSuccess('/project', $release, timeoutSeconds: 0);

    expect($received)->toBe([[
        'gh', 'run', 'list', '--repo', 'github.com/example/project',
        '--workflow', 'tests.yml', '--event', 'push', '--branch', '1.x', '--commit', $release->commit,
        '--limit', '1', '--json', 'headSha,headBranch,event,status,conclusion,url',
    ], '/project']);
});

it('rejects completed workflows without a success conclusion', function (string $conclusion): void {
    $release = preparedGitHubReleaseForGate();
    $output = json_encode([[
        'headSha' => $release->commit,
        'headBranch' => '1.x',
        'event' => 'push',
        'status' => 'completed',
        'conclusion' => $conclusion,
        'url' => 'https://github.com/example/project/actions/runs/1',
    ]], flags: JSON_THROW_ON_ERROR);
    $gate = new GitHubReleaseGate(fn (): Process => processReturningGitHubRun($output));

    expect(fn () => $gate->waitForSuccess('/project', $release, timeoutSeconds: 0))
        ->toThrow(RuntimeException::class, "concluded {$conclusion}");
})->with(['failure', 'cancelled', 'skipped', 'neutral', 'stale']);

it('fails closed when the matching run is missing or still pending', function (?string $status): void {
    $release = preparedGitHubReleaseForGate();
    $output = json_encode($status === null ? [] : [[
        'headSha' => $release->commit,
        'headBranch' => '1.x',
        'event' => 'push',
        'status' => $status,
        'conclusion' => '',
        'url' => 'https://github.com/example/project/actions/runs/1',
    ]], flags: JSON_THROW_ON_ERROR);
    $gate = new GitHubReleaseGate(fn (): Process => processReturningGitHubRun($output));

    expect(fn () => $gate->waitForSuccess('/project', $release, timeoutSeconds: 0))
        ->toThrow(RuntimeException::class, 'Timed out waiting for tests.yml');
})->with([null, 'in_progress']);

it('rejects success from another commit, branch or event', function (string $key, string $value): void {
    $release = preparedGitHubReleaseForGate();
    $run = [
        'headSha' => $release->commit,
        'headBranch' => '1.x',
        'event' => 'push',
        'status' => 'completed',
        'conclusion' => 'success',
        'url' => 'https://github.com/example/project/actions/runs/1',
    ];
    $run[$key] = $value;
    $output = json_encode([$run], flags: JSON_THROW_ON_ERROR);
    $gate = new GitHubReleaseGate(fn (): Process => processReturningGitHubRun($output));

    expect(fn () => $gate->waitForSuccess('/project', $release, timeoutSeconds: 0))
        ->toThrow(RuntimeException::class, 'did not return a push workflow run for the prepared release commit');
})->with([
    'older SHA' => ['headSha', str_repeat('b', 40)],
    'other branch' => ['headBranch', '2.x'],
    'pull request' => ['event', 'pull_request'],
]);

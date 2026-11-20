<?php

use App\Support\Release\GitHubReleaseGate;
use App\Support\Release\GitHubReleaseWorkflow;
use App\Support\Release\GitHubWorkflowProgress;
use App\Support\Release\PreparedRelease;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;

function preparedGitHubReleaseForGate(): PreparedRelease
{
    return new PreparedRelease(
        'github.com/example/project',
        [new GitHubReleaseWorkflow('tests.yml', 42)],
        '1.x',
        str_repeat('a', 40),
        '1.0.0',
        '1.0.1',
        '1.0.1 - Fix a release check',
        'Release notes.',
    );
}

function processReturningGitHubRun(string $output, int|float $timeout = 1): Process
{
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with($timeout);
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
            'workflowDatabaseId' => 42,
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
        '--workflow', '42', '--event', 'push', '--branch', '1.x', '--commit', $release->commit,
        '--limit', '1', '--json', 'headSha,headBranch,event,status,conclusion,url,databaseId,attempt,workflowDatabaseId',
    ], '/project']);
});

it('reports workflow transitions, active steps, and a heartbeat while waiting', function (): void {
    $release = preparedGitHubReleaseForGate();
    $url = 'https://github.com/example/project/actions/runs/1';
    $run = [
        'headSha' => $release->commit,
        'workflowDatabaseId' => 42,
        'headBranch' => $release->branch,
        'event' => 'push',
        'url' => $url,
        'databaseId' => 1,
        'attempt' => 2,
    ];
    $runs = [
        [0, []],
        [5, [$run + ['status' => 'queued', 'conclusion' => '']]],
        [10, [$run + ['status' => 'in_progress', 'conclusion' => '']]],
        [15, [$run + ['status' => 'in_progress', 'conclusion' => '']]],
        [40, [$run + ['status' => 'in_progress', 'conclusion' => '']]],
        [45, [$run + ['status' => 'completed', 'conclusion' => 'success']]],
    ];
    $jobQueries = 0;
    $now = 0;
    $gate = new GitHubReleaseGate(
        function (array $command) use (&$now, &$runs, &$jobQueries, $run, $release): Process {
            if ($command[2] === 'view') {
                $jobQueries++;
                expect($command)->toContain('1', '--attempt', '2', '--repo', $release->repository);

                return processReturningGitHubRun(json_encode($run + ['jobs' => [[
                    'name' => 'PHP 8.5',
                    'status' => 'in_progress',
                    'steps' => [
                        ['name' => 'Checkout', 'status' => 'completed'],
                        ['name' => 'Run Pest', 'status' => 'in_progress'],
                    ],
                ]]], flags: JSON_THROW_ON_ERROR), 2.0);
            }

            [$seconds, $response] = array_shift($runs);
            $now = $seconds * 1_000_000_000;

            return processReturningGitHubRun(json_encode($response, flags: JSON_THROW_ON_ERROR), 30);
        },
        function () use (&$now): int {
            return $now;
        },
    );
    $progress = [];
    Sleep::fake();

    try {
        $gate->waitForSuccess('/project', $release, onProgress: function (GitHubWorkflowProgress $update) use (&$progress): void {
            $progress[] = [$update->workflow, $update->status, $update->conclusion, $update->elapsedSeconds, $update->url, $update->activeJobs];
        });
    } finally {
        Sleep::fake(false);
    }

    expect($progress)->toBe([
        ['tests.yml', null, null, 0, null, []],
        ['tests.yml', 'queued', '', 5, $url, []],
        ['tests.yml', 'in_progress', '', 10, $url, ['PHP 8.5 / Run Pest']],
        ['tests.yml', 'in_progress', '', 40, $url, ['PHP 8.5 / Run Pest']],
        ['tests.yml', 'completed', 'success', 45, $url, []],
    ])->and($jobQueries)->toBe(2);
});

it('rejects completed workflows without a success conclusion', function (string $conclusion): void {
    $release = preparedGitHubReleaseForGate();
    $output = json_encode([[
        'headSha' => $release->commit,
        'workflowDatabaseId' => 42,
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
        'workflowDatabaseId' => 42,
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

it('rejects success from another commit, branch, event or workflow', function (string $key, string|int $value): void {
    $release = preparedGitHubReleaseForGate();
    $run = [
        'headSha' => $release->commit,
        'workflowDatabaseId' => 42,
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
    'different workflow' => ['workflowDatabaseId', 43],
]);

it('rechecks workflows that passed earlier when another required workflow finishes', function (): void {
    $release = preparedGitHubReleaseForGate()->withWorkflows([
        new GitHubReleaseWorkflow('tests.yml', 42),
        new GitHubReleaseWorkflow('coverage.yml', 43),
    ]);
    $responses = [
        [42, 'completed', 'success', 1],
        [43, 'queued', '', 1],
        [42, 'queued', '', 2],
        [43, 'completed', 'success', 1],
        [42, 'completed', 'success', 2],
        [43, 'completed', 'success', 1],
    ];
    $gate = new GitHubReleaseGate(function (array $command) use (&$responses, $release): Process {
        [$id, $status, $conclusion, $attempt] = array_shift($responses);
        expect($command)->toContain('--workflow', (string) $id);

        return processReturningGitHubRun(json_encode([[
            'headSha' => $release->commit,
            'headBranch' => $release->branch,
            'event' => 'push',
            'status' => $status,
            'conclusion' => $conclusion,
            'url' => "https://github.com/example/project/actions/runs/{$id}",
            'databaseId' => $id,
            'workflowDatabaseId' => $id,
            'attempt' => $attempt,
        ]], flags: JSON_THROW_ON_ERROR), 30);
    });
    $attempts = [];
    Sleep::fake();

    try {
        $gate->waitForSuccess('/project', $release, onProgress: function (GitHubWorkflowProgress $progress) use (&$attempts): void {
            if ($progress->workflow === 'tests.yml') {
                $attempts[] = [$progress->status, $progress->attempt];
            }
        });
    } finally {
        Sleep::fake(false);
    }

    expect($attempts)->toBe([
        ['completed', 1],
        ['queued', 2],
        ['completed', 2],
    ])->and($responses)->toBe([]);
});

it('reports only unresolved workflows when the group times out', function (): void {
    $release = preparedGitHubReleaseForGate()->withWorkflows([
        new GitHubReleaseWorkflow('tests.yml', 42),
        new GitHubReleaseWorkflow('coverage.yml', 43),
    ]);
    $gate = new GitHubReleaseGate(fn (array $command): Process => processReturningGitHubRun(json_encode(
        in_array('42', $command, true)
            ? [[
                'headSha' => $release->commit,
                'headBranch' => $release->branch,
                'event' => 'push',
                'status' => 'completed',
                'conclusion' => 'success',
                'url' => 'https://github.com/example/project/actions/runs/42',
                'workflowDatabaseId' => 42,
            ]]
            : [],
        flags: JSON_THROW_ON_ERROR,
    )));

    expect(fn () => $gate->waitForSuccess('/project', $release, timeoutSeconds: 0))
        ->toThrow(RuntimeException::class, 'Timed out waiting for coverage.yml (no matching push run was found)');
});

it('resolves a list of workflow names and filenames to unique GitHub identities', function (): void {
    $catalog = [
        ['workflows' => [
            ['id' => 42, 'name' => 'Tests', 'path' => '.github/workflows/tests.yml', 'state' => 'active'],
        ]],
        ['workflows' => [
            ['id' => 43, 'name' => 'Coverage', 'path' => '.github/workflows/coverage.yml', 'state' => 'active'],
        ]],
    ];
    $gate = new GitHubReleaseGate(function (array $command) use ($catalog): Process {
        expect($command)->toContain('repos/example/project/actions/workflows?per_page=100', '--hostname', 'github.com', '--paginate');

        return processReturningGitHubRun(json_encode($catalog, flags: JSON_THROW_ON_ERROR), 30);
    });

    expect($gate->resolveWorkflows('/project', 'github.com/example/project', ['tests.yml', 'coverage']))
        ->toEqual([new GitHubReleaseWorkflow('tests.yml', 42), new GitHubReleaseWorkflow('coverage', 43)]);
    expect(fn () => $gate->resolveWorkflows('/project', 'github.com/example/project', ['tests.yml', 'Tests']))
        ->toThrow(RuntimeException::class, 'refers to the same workflow as tests.yml');
});

it('rejects invalid required workflow configuration', function (mixed $setting): void {
    expect(fn () => GitHubReleaseWorkflow::configuredSelectors($setting))
        ->toThrow(RuntimeException::class, 'release.workflow');
})->with([
    'empty list' => [[]],
    'map' => [['tests' => 'tests.yml']],
    'blank selector' => [['tests.yml', ' ']],
    'wrong type' => [['tests.yml', 202]],
    'duplicate selector' => [['tests.yml', 'tests.yml']],
]);

<?php

use App\Support\Release\GitHubCliReleaseSource;
use Symfony\Component\Process\Process;

it('reads published release tags from every GitHub API page', function () {
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(60.0);
    $process->shouldReceive('run')->once();
    $process->shouldReceive('isSuccessful')->once()->andReturnTrue();
    $process->shouldReceive('getOutput')->once()->andReturn(json_encode([
        [
            ['tag_name' => '1.0.0', 'draft' => false],
            ['tag_name' => '1.1.0-alpha.1', 'draft' => false],
            ['tag_name' => 'ignored-draft', 'draft' => true],
        ],
        [
            ['tag_name' => '1.1.0-beta.1', 'draft' => false],
        ],
    ], flags: JSON_THROW_ON_ERROR));

    $source = new GitHubCliReleaseSource(
        function (array $command, string $workingDirectory) use ($process): Process {
            expect($command)->toBe([
                'gh',
                'api',
                'repos/{owner}/{repo}/releases?per_page=100',
                '--paginate',
                '--slurp',
            ])->and($workingDirectory)->toBe('/project');

            return $process;
        },
    );

    expect($source->versions('/project'))->toBe([
        '1.0.0',
        '1.1.0-alpha.1',
        '1.1.0-beta.1',
    ]);
});

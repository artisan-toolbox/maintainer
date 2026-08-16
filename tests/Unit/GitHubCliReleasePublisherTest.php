<?php

use App\Support\Release\GitHubCliReleasePublisher;
use Symfony\Component\Process\Process;

it('publishes stable and prerelease versions with GitHub CLI', function (bool $prerelease, bool $expectsFlag) {
    $received = null;
    $publisher = new GitHubCliReleasePublisher(
        function (array $command, string $projectRoot) use (&$received): Process {
            $received = [$command, $projectRoot];

            return new Process([PHP_BINARY, '-r', 'echo "https://github.com/example/project/releases/tag/2.0.0";']);
        },
    );

    $url = $publisher->publish(
        '/project',
        $prerelease ? '2.0.0-beta.1' : '2.0.0',
        '2.x',
        'Release title',
        "## Added\n\nRelease body.",
        $prerelease,
    );

    expect($url)->toStartWith('https://github.com/example/project/releases/tag/')
        ->and($received[0])->toContain('gh', 'release', 'create', '--target', '2.x', '--title', 'Release title', '--notes')
        ->and(in_array('--prerelease', $received[0], true))->toBe($expectsFlag)
        ->and($received[1])->toBe('/project');
})->with([
    'stable' => [false, false],
    'prerelease' => [true, true],
]);

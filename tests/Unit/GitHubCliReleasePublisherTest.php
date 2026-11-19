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

it('publishes a previously verified tag in the same repository with notes on stdin', function () {
    $received = [];
    $publisher = new GitHubCliReleasePublisher(
        function (array $command, string $projectRoot) use (&$received): Process {
            $received = [$command, $projectRoot];

            return new Process([PHP_BINARY, '-r', 'echo stream_get_contents(STDIN);']);
        },
    );
    $commit = str_repeat('a', 40);
    $notes = 'Reviewed release notes.';

    expect($publisher->publish('/project', '2.0.0-beta.1', $commit, 'Release title', $notes, true, 'github.com/example/project'))
        ->toBe($notes)
        ->and($received)->toBe([[
            'gh', 'release', 'create', '2.0.0-beta.1', '--target', $commit, '--title', 'Release title',
            '--repo', 'github.com/example/project', '--verify-tag', '--notes-file', '-', '--prerelease',
        ], '/project']);
});

it('recovers publication only when GitHub returns the same reviewed release', function (?string $mismatch, mixed $value) {
    $received = [];
    $url = 'https://github.com/example/project/releases/tag/2.0.0-beta.1';
    $release = [
        'url' => $url,
        'tagName' => '2.0.0-beta.1',
        'name' => 'Release title',
        'body' => "Reviewed notes.\n",
        'isDraft' => false,
        'isPrerelease' => true,
    ];
    if ($mismatch !== null) {
        $release[$mismatch] = $value;
    }
    $publisher = new GitHubCliReleasePublisher(
        function (array $command) use (&$received, $release): Process {
            $received[] = $command;

            return $command[2] === 'create'
                ? new Process([PHP_BINARY, '-r', 'fwrite(STDERR, "The release already exists."); exit(1);'])
                : new Process([PHP_BINARY, '-r', 'echo $argv[1];', json_encode($release, flags: JSON_THROW_ON_ERROR)]);
        },
    );
    $publish = fn (): string => $publisher->publish(
        '/project',
        '2.0.0-beta.1',
        str_repeat('a', 40),
        'Release title',
        'Reviewed notes.',
        true,
        'github.com/example/project',
    );

    if ($mismatch === null) {
        expect($publish())->toBe($url);
    } else {
        expect($publish)->toThrow(RuntimeException::class, 'A matching published release could not be confirmed');
    }

    expect($received)->toHaveCount(2)
        ->and($received[1])->toBe([
            'gh', 'release', 'view', '2.0.0-beta.1', '--repo', 'github.com/example/project',
            '--json', 'url,tagName,name,body,isDraft,isPrerelease',
        ]);
})->with([
    'same release' => [null, null],
    'different tag' => ['tagName', '2.0.0-beta.2'],
    'different title' => ['name', 'Other release'],
    'different body' => ['body', 'Different notes.'],
    'draft release' => ['isDraft', true],
    'stable release' => ['isPrerelease', false],
]);

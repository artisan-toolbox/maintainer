<?php

use App\Support\Release\GitCliReleaseRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('fetches a GitHub release tag that is missing from the local clone', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-release-tag-'.Str::uuid();
    $remote = $directory.DIRECTORY_SEPARATOR.'remote.git';
    $project = $directory.DIRECTORY_SEPARATOR.'project';
    $files->makeDirectory($project, recursive: true);

    try {
        new Process(['git', 'init', '--bare', $remote])->mustRun();

        foreach ([
            ['init', '--initial-branch=1.x'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $project)->mustRun();
        }

        $files->put($project.'/release.txt', "released\n");

        foreach ([
            ['add', '.'],
            ['commit', '-m', 'Release beta'],
            ['tag', '1.0.0-beta.1'],
            ['remote', 'add', 'origin', $remote],
            ['push', 'origin', 'HEAD', '--tags'],
            ['tag', '--delete', '1.0.0-beta.1'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $project)->mustRun();
        }

        $missing = new Process(['git', 'rev-parse', '--verify', '--quiet', '1.0.0-beta.1^{commit}'], $project);
        $missing->run();
        expect($missing->isSuccessful())->toBeFalse();

        (new GitCliReleaseRepository)->ensureLocalReference($project, '1.0.0-beta.1');

        $resolved = new Process(['git', 'rev-parse', '--verify', '--quiet', '1.0.0-beta.1^{commit}'], $project);
        $resolved->run();

        expect($resolved->isSuccessful())->toBeTrue();
    } finally {
        deleteTemporaryDirectory($directory);
    }
});

it('keeps an existing local release tag without requiring a remote', function () {
    withinTemporaryProject(function (string $directory): void {
        foreach ([
            ['init', '--initial-branch=1.x'],
            ['config', 'user.name', 'Maintainer Tests'],
            ['config', 'user.email', 'maintainer@example.com'],
            ['add', '.'],
            ['commit', '-m', 'Release beta'],
            ['tag', '1.0.0-beta.1'],
        ] as $arguments) {
            new Process(['git', ...$arguments], $directory)->mustRun();
        }

        (new GitCliReleaseRepository)->ensureLocalReference($directory, '1.0.0-beta.1');

        expect(true)->toBeTrue();
    });
});

it('reports an actionable error when a missing release tag cannot be fetched', function () {
    withinTemporaryProject(function (string $directory): void {
        new Process(['git', 'init', '--initial-branch=1.x'], $directory)->mustRun();

        expect(fn () => (new GitCliReleaseRepository)->ensureLocalReference($directory, '1.0.0-beta.1'))
            ->toThrow(RuntimeException::class, 'GitHub release tag 1.0.0-beta.1 is not available locally and could not be fetched from origin');
    });
});

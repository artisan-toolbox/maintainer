<?php

use App\Support\Git\GitCommitRepository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = temporaryTestDirectory('maintainer-commit-');
    $this->files->makeDirectory($this->directory, recursive: true);
    $this->files->put($this->directory.'/keep.txt', "original\n");
    $this->files->put($this->directory.'/rename-me.txt', "rename me\n");

    foreach ([
        ['init', '--initial-branch=main'],
        ['config', 'user.name', 'Maintainer Tests'],
        ['config', 'user.email', 'maintainer@example.com'],
        ['add', '.'],
        ['commit', '-m', 'Initial commit'],
    ] as $arguments) {
        new Process(['git', ...$arguments], $this->directory)->mustRun();
    }
});

afterEach(function () {
    deleteTemporaryDirectory($this->directory);
});

it('lists modified, untracked, and renamed files', function () {
    $this->files->put($this->directory.'/keep.txt', "changed\n");
    $this->files->put($this->directory.'/new.txt', "new\n");
    new Process(['git', 'mv', 'rename-me.txt', 'renamed.txt'], $this->directory)->mustRun();

    $changes = (new GitCommitRepository)->changes($this->directory);

    expect(array_map(fn ($change): string => $change->label, $changes))
        ->toContain(' M keep.txt', '?? new.txt', 'R  rename-me.txt -> renamed.txt');

    $rename = collect($changes)->first(fn ($change): bool => str_contains($change->label, ' -> '));

    expect($rename->paths)->toBe(['rename-me.txt', 'renamed.txt']);
});

it('stages and commits only the selected files', function () {
    $this->files->put($this->directory.'/keep.txt', "changed\n");
    $this->files->put($this->directory.'/new.txt', "new\n");
    new Process(['git', 'add', 'new.txt'], $this->directory)->mustRun();
    $repository = new GitCommitRepository;
    $changes = $repository->changes($this->directory);
    $selected = array_values(array_filter(
        $changes,
        fn ($change): bool => $change->paths === ['keep.txt'],
    ));

    $repository->stageOnly($this->directory, $selected);

    expect($repository->stagedStatus($this->directory))->toBe("M\tkeep.txt")
        ->and(array_map(fn ($change): string => $change->label, $repository->changes($this->directory)))
        ->toContain('?? new.txt')
        ->and($repository->stagedDiff($this->directory))->toContain('+changed')
        ->not->toContain('new.txt');

    $repository->commit($this->directory, 'test(commit): select files');
    $subject = new Process(['git', 'log', '-1', '--pretty=%s'], $this->directory)->mustRun()->getOutput();

    expect(trim($subject))->toBe('test(commit): select files')
        ->and($this->files->exists($this->directory.'/new.txt'))->toBeTrue();
});

it('stages both sides of a selected rename', function () {
    new Process(['git', 'mv', 'rename-me.txt', 'renamed.txt'], $this->directory)->mustRun();
    $repository = new GitCommitRepository;
    $rename = collect($repository->changes($this->directory))
        ->first(fn ($change): bool => str_contains($change->label, ' -> '));

    $repository->stageOnly($this->directory, [$rename]);

    expect($repository->stagedStatus($this->directory))
        ->toContain("R100\trename-me.txt\trenamed.txt")
        ->and($repository->stagedDiff($this->directory))
        ->toContain('rename from rename-me.txt')
        ->toContain('rename to renamed.txt');
});

it('ignores an index-only path removed before the selected files are staged', function () {
    $this->files->put($this->directory.'/temporary-name.txt', "new file\n");
    new Process(['git', 'add', 'temporary-name.txt'], $this->directory)->mustRun();
    $this->files->move(
        $this->directory.'/temporary-name.txt',
        $this->directory.'/permanent-name.txt',
    );
    $repository = new GitCommitRepository;

    $changes = $repository->changes($this->directory);
    $repository->stageOnly($this->directory, $changes);

    expect($repository->stagedStatus($this->directory))
        ->toBe("A\tpermanent-name.txt")
        ->and($repository->stagedDiff($this->directory))
        ->toContain('permanent-name.txt')
        ->not->toContain('temporary-name.txt');
});

it('never stages every file when the selection is empty', function () {
    $this->files->put($this->directory.'/keep.txt', "changed\n");
    $repository = new GitCommitRepository;

    expect(fn () => $repository->stageOnly($this->directory, []))
        ->toThrow(RuntimeException::class, 'At least one changed file must be selected.')
        ->and($repository->stagedDiff($this->directory))->toBe('');
});

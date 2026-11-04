<?php

use App\Support\Release\ReadmeHeadWriter;
use ArtisanToolbox\Maintainer\Maintainer;
use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('builds the Maintainer executable before versioning itself', function () {
    Process::fake();

    Maintainer::beforeVersioning('1.0.0', '1.0.1');

    $projectRoot = base_path();

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        PHP_BINARY,
        base_path('maintainer'),
        'app:build',
    ] && $process->path === $projectRoot && $process->timeout === null);
});

it('keeps its README badges tied to the published release', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $contents = $files->get(dirname(__DIR__, 2).'/README.md');
        $files->put($readme, $contents);
        $writer = new ReadmeHeadWriter($files);
        $head = (new Maintainer)->readmeHead();

        expect($writer->update($directory, $head, Maintainer::VERSION))->toBeFalse()
            ->and($writer->update($directory, $head, '2.0.0-beta.1'))->toBeFalse()
            ->and($files->get($readme))->toBe($contents)
            ->and(array_map(static fn (Badge $badge): array => [
                $badge->imgAlt(), $badge->link(), $badge->imgSrc(),
            ], $head->badges()))->toBe([
                ['Release', 'https://github.com/artisan-toolbox/maintainer/releases', 'https://img.shields.io/github/v/release/artisan-toolbox/maintainer?label=Release&style=flat-square'],
                ['PHP', 'https://artisantoolbox.wsssoftware.com.br/packages/maintainer/#requirements', 'https://img.shields.io/badge/PHP-%5E8.5-777BB4?style=flat-square'],
                ['Tests', 'https://github.com/artisan-toolbox/maintainer/actions/workflows/tests.yml?query=branch%3A1.x', 'https://img.shields.io/github/actions/workflow/status/artisan-toolbox/maintainer/tests.yml?branch=1.x&label=Tests&style=flat-square'],
                ['Docs', 'https://artisantoolbox.wsssoftware.com.br/packages/maintainer/', 'https://img.shields.io/badge/Docs-Read-blue?style=flat-square'],
            ]);
    });
});

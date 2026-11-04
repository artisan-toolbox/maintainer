<?php

use App\Support\Release\ReadmeHeadWriter;
use ArtisanToolbox\Maintainer\Maintainer;
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

it('manages its own README header with the selected version', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files): void {
        $readme = $directory.'/README.md';
        $contents = $files->get(dirname(__DIR__, 2).'/README.md');
        $files->put($readme, $contents);
        $writer = new ReadmeHeadWriter($files);
        $head = (new Maintainer)->readmeHead();

        expect($writer->update($directory, $head, Maintainer::VERSION))->toBeFalse()
            ->and($writer->update($directory, $head, '2.0.0-beta.1'))->toBeTrue()
            ->and($files->get($readme))->toBe(str_replace(
                'version-'.Maintainer::VERSION.'-blue',
                'version-2.0.0--beta.1-blue',
                $contents,
            ));
    });
});

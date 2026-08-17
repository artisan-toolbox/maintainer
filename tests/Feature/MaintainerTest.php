<?php

use ArtisanToolbox\Maintainer\Maintainer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('builds the Maintainer executable before versioning itself', function () {
    Process::fake();

    Maintainer::beforeVersioning('1.0.0', '1.0.1');

    $projectRoot = dirname(__DIR__, 2);

    Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
        PHP_BINARY,
        $projectRoot.DIRECTORY_SEPARATOR.'maintainer',
        'app:build',
    ] && $process->path === $projectRoot && $process->timeout === null);
});

<?php

use ArtisanToolbox\Maintainer\Maintainer;
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

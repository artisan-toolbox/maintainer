<?php

use Illuminate\Filesystem\Filesystem;

it('avoids legacy command input and output helpers', function () {
    $commandFiles = (new Filesystem)->allFiles(dirname(__DIR__, 2).'/app/Commands');

    foreach ($commandFiles as $commandFile) {
        expect(file_get_contents($commandFile->getPathname()))
            ->not->toMatch('/\$this->(?:alert|ask|choice|confirm|error|info|line|newLine|question|secret|warn)\s*\(/');
    }
});

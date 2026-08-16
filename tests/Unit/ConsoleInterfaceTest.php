<?php

it('avoids legacy command input and output helpers', function () {
    $commandFiles = glob(dirname(__DIR__, 2).'/app/Commands/*.php');

    expect($commandFiles)->not->toBeFalse();

    foreach ($commandFiles as $commandFile) {
        expect(file_get_contents($commandFile))
            ->not->toMatch('/\$this->(?:alert|ask|choice|confirm|error|info|line|newLine|question|secret|warn)\s*\(/');
    }
});

<?php

use App\Support\Configuration\PhpConfigurationExporter;

it('exports nested values as a conventional PHP configuration file', function () {
    $contents = new PhpConfigurationExporter()->export([
        'quality' => [
            'phpstan' => [
                'memory_limit' => '4G',
                'paths' => ['app', 'tests'],
            ],
        ],
        'enabled' => true,
    ]);

    expect($contents)
        ->toStartWith("<?php\n\nreturn [")
        ->toContain("'memory_limit' => '4G'")
        ->toContain("'paths' => [\n")
        ->toEndWith("];\n");
});

<?php

use App\Support\Quality\LaravelProjectType;
use App\Support\Quality\LaravelProjectTypeDetector;
use Illuminate\Filesystem\Filesystem;

it('detects Composer projects as applications and libraries as packages', function () {
    $files = new Filesystem;
    $directory = temporaryTestDirectory('maintainer-type-');
    $files->makeDirectory($directory, recursive: true);
    $detector = new LaravelProjectTypeDetector($files);

    try {
        $files->put($directory.'/composer.json', "{\"type\": \"project\"}\n");
        expect($detector->detect($directory))->toBe(LaravelProjectType::Application);

        $files->put($directory.'/composer.json', "{\"type\": \"library\"}\n");
        expect($detector->detect($directory))->toBe(LaravelProjectType::Package);
    } finally {
        $files->deleteDirectory($directory);
    }
});

<?php

use App\Support\Quality\QualityTool;

it('recognizes supported PHPStan configuration filenames', function () {
    expect(QualityTool::PhpStan->configurationFilenames())->toBe([
        'phpstan.neon',
        'phpstan.neon.dist',
        'phpstan.dist.neon',
    ]);
});

it('recognizes supported PHPUnit configuration filenames for Pest', function () {
    expect(QualityTool::Pest->configurationFilenames())->toBe([
        'phpunit.xml',
        'phpunit.xml.dist',
    ]);
});

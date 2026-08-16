<?php

use App\Support\Quality\QualityTool;

it('builds commands that explicitly select the project configuration', function (
    QualityTool $tool,
    array $expected,
) {
    expect($tool->command('/project/vendor/bin/'.$tool->value, '/project/config-file'))->toBe($expected);
})->with([
    'Pint' => [
        QualityTool::Pint,
        ['/project/vendor/bin/pint', '--config', '/project/config-file'],
    ],
    'Rector' => [
        QualityTool::Rector,
        ['/project/vendor/bin/rector', 'process', '--config', '/project/config-file'],
    ],
    'PHPStan' => [
        QualityTool::PhpStan,
        ['/project/vendor/bin/phpstan', 'analyse', '--configuration', '/project/config-file'],
    ],
    'Pest' => [
        QualityTool::Pest,
        ['/project/vendor/bin/pest', '--configuration', '/project/config-file'],
    ],
]);

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

it('appends project-specific arguments to the tool command', function () {
    expect(QualityTool::PhpStan->command(
        '/project/vendor/bin/phpstan',
        '/project/phpstan.neon',
        ['--memory-limit=2G'],
    ))->toBe([
        '/project/vendor/bin/phpstan',
        'analyse',
        '--configuration',
        '/project/phpstan.neon',
        '--memory-limit=2G',
    ]);
});

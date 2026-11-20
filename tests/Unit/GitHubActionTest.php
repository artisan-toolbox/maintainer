<?php

use Symfony\Component\Yaml\Yaml;

it('calls the root action with supported inputs from the reusable workflow', function () {
    $repository = dirname(__DIR__, 2);
    $action = Yaml::parseFile($repository.'/action.yml');
    $workflow = Yaml::parseFile($repository.'/.github/workflows/ci.yml');

    $setupSteps = array_values(array_filter(
        $workflow['jobs']['quality']['steps'],
        fn (array $step): bool => ($step['uses'] ?? null) === '$/',
    ));

    expect($action['runs']['using'])->toBe('composite')
        ->and($setupSteps)->toHaveCount(1)
        ->and(array_diff_key($setupSteps[0]['with'], $action['inputs']))->toBe([]);
});

it('runs the package tests on PHP 8.5 and 8.6 on Ubuntu and Windows', function () {
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/tests.yml');
    $job = $workflow['jobs']['tests'];
    $matrix = $job['strategy']['matrix'];
    $steps = array_column($job['steps'], null, 'name');

    expect($matrix['php'] ?? [])->toContain('8.5', '8.6')
        ->and($matrix['os'])->toContain('ubuntu-latest', 'windows-latest')
        ->and($matrix)->not->toHaveKey('exclude')
        ->and($steps['Set up CI dependencies']['with']['php-version'])->toBe('${{ matrix.php }}')
        ->and($steps['Run tests'])->not->toHaveKey('if');
});

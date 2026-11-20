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

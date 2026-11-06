<?php

it('requires a PM2 ecosystem configuration file', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configureFile: false);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('The pm2_config_file configuration must contain the ecosystem file path.')
        ->and($this->files->exists($this->directory.'/pm2.log'))
        ->toBeFalse();
});

it('reports when PM2 is not installed on the host', function () {
    $packageRoot = dirname(__DIR__, 3);
    $missingPm2 = str_replace('\\', '/', $this->directory.'/missing-pm2');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $missingPm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('PM2 is not installed or is not available');
});

it('fails before mutations when the ecosystem or inspection runtime is unavailable', function (string $failure) {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"old-app"}]');
    $configuration = $failure === 'node' ? ['bin/node' => $this->directory.'/missing-node'] : [];
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: $configuration);

    if ($failure === 'file') {
        $this->files->delete($this->directory.'/release/ecosystem.config.cjs');
    } elseif ($failure === 'syntax') {
        $this->files->put($this->directory.'/release/ecosystem.config.cjs', 'invalid Javascript {{{');
    } elseif ($failure === 'parser') {
        $this->files->delete($this->directory.'/lib/Common.js');
    }

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($this->files->exists($this->directory.'/pm2.log'))->toBeFalse();
})->with(['file', 'node', 'syntax', 'parser']);

it('propagates inspection and final persistence failures', function (string $failure) {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[]');
    $this->files->put($this->directory.'/pm2-fail.json', json_encode([$failure => true], JSON_THROW_ON_ERROR));
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('Simulated PM2 failure');

    if ($failure === 'jlist') {
        expect(trim($this->files->get($this->directory.'/pm2.log')))->toBe('jlist');
    } else {
        expect(trim($this->files->get($this->directory.'/pm2.log')))->toEndWith('save --force');
    }
})->with(['jlist', 'save']);

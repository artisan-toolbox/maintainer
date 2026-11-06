<?php

it('replaces existing PM2 processes with the configured ecosystem file', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"old-app","pm2_env":{"namespace":"/var/www/current-app"}}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue()
        ->and(preg_split('/\R/', trim($this->files->get($this->directory.'/pm2.log'))))
        ->toBe([
            'jlist',
            'delete 0',
            'save --force',
            'start '.$this->directory.'/release/ecosystem.config.cjs --namespace=/var/www/current-app --update-env',
            'save --force',
        ]);
});

it('does not delete PM2 processes when the current process list is empty', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue()
        ->and(preg_split('/\R/', trim($this->files->get($this->directory.'/pm2.log'))))
        ->toBe([
            'jlist',
            'start '.$this->directory.'/release/ecosystem.config.cjs --namespace=/var/www/current-app --update-env',
            'save --force',
        ]);
});

it('preserves other namespaces while replacing its own and legacy processes', function () {
    $packageRoot = dirname(__DIR__, 3);
    $processes = [
        ['pm_id' => 0, 'name' => 'current-worker', 'pm2_env' => ['namespace' => '/var/www/current-app']],
        ['pm_id' => 1, 'name' => '/var/www/current-app', 'pm2_env' => ['namespace' => 'another-app']],
        ['pm_id' => 2, 'name' => 'legacy-default', 'pm2_env' => ['namespace' => 'default']],
        ['pm_id' => 3, 'name' => 'legacy-missing'],
        ['pm_id' => 4, 'name' => 'legacy-empty', 'pm2_env' => ['namespace' => '']],
        ['pm_id' => 5, 'name' => 'legacy-null', 'pm2_env' => ['namespace' => null]],
    ];
    $pm2 = installFakePm2Binary($this->directory, $this->files, json_encode($processes, JSON_THROW_ON_ERROR));
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1, 100])
        ->and($this->files->get($this->directory.'/pm2.log'))->not->toContain('delete all');
});

it('keeps legacy processes when cleanup is disabled', function () {
    $packageRoot = dirname(__DIR__, 3);
    $processes = '[{"pm_id":0,"name":"current","pm2_env":{"namespace":"custom"}},{"pm_id":1,"name":"legacy","pm2_env":{"namespace":"default"}},{"pm_id":2,"name":"older"}]';
    $pm2 = installFakePm2Binary($this->directory, $this->files, $processes);
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => 'custom', 'pm2_cleanup_legacy' => false]);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1, 2, 100]);
});

it('replaces an explicitly selected default namespace even with legacy cleanup disabled', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"current","pm2_env":{"namespace":"default"}},{"pm_id":1,"name":"older"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => 'default', 'pm2_cleanup_legacy' => false]);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1, 100]);
});

it('persists successful deletions even when the subsequent ecosystem start fails', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"old-app","pm2_env":{"namespace":"default"}}]');
    $this->files->put($this->directory.'/pm2-fail.json', '{"start":true}');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(json_decode($this->files->get($this->directory.'/dump.json'), true))->toBe([])
        ->and($this->files->get($this->directory.'/pm2.log'))->toContain("delete 0\nsave --force\nstart ");
});

it('persists each deletion before another deletion fails', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"first"},{"pm_id":1,"name":"second"}]');
    $this->files->put($this->directory.'/pm2-fail.json', '{"delete:1":true}');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1])
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe("jlist\ndelete 0\nsave --force\ndelete 1");
});

it('stops before starting the ecosystem if persisting a deletion fails', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"first"}]');
    $this->files->put($this->directory.'/pm2-fail.json', '{"save":true}');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe("jlist\ndelete 0\nsave --force");
});

it('persists applications created by a partially failed ecosystem start', function (bool $failSave) {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[]');
    $this->files->put($this->directory.'/pm2-fail.json', json_encode(['partial_start' => true, ...($failSave ? ['save' => true] : [])], JSON_THROW_ON_ERROR));
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('Simulated partial PM2 startup failure')
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toEndWith('save --force');

    if ($failSave) {
        expect($process->getOutput())->toContain('Unable to persist the PM2 process list after the failed start');
    } else {
        expect(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([100]);
    }
})->with([false, true]);

it('preserves PM2 modules while cleaning legacy application processes', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"pm2-logrotate","pm2_env":{"namespace":"default","pmx_module":true}},{"pm_id":1,"name":"old-app"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/processes.json'), true), 'pm_id'))->toBe([0, 100]);
});

it('treats the reserved all namespace as data rather than a global deletion target', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"app","pm2_env":{"namespace":"all"}},{"pm_id":1,"name":"other","pm2_env":{"namespace":"other"}}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => 'all']);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/processes.json'), true), 'pm_id'))->toBe([1, 100])
        ->and($this->files->get($this->directory.'/pm2.log'))->not->toContain('delete all');
});

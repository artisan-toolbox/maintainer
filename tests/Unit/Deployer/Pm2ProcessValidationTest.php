<?php

it('rejects an invalid PM2 JSON process list', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, 'not-json');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('pm2 jlist returned invalid JSON')
        ->and(trim($this->files->get($this->directory.'/pm2.log')))
        ->toBe('jlist');
});

it('rejects malformed PM2 process lists before changing any processes', function (string $processList) {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, $processList);
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe('jlist')
        ->and($this->files->get($this->directory.'/dump.json'))->toBe($processList);
})->with([
    'object' => '{}',
    'numeric object' => '{"0":{"pm_id":0,"name":"app"}}',
    'null' => 'null',
    'scalar entry' => '[1]',
    'list entry' => '[[]]',
    'missing identifier' => '[{"name":"app"}]',
    'global identifier' => '[{"pm_id":"all","name":"app"}]',
    'shell identifier' => '[{"pm_id":"0; touch injected","name":"app"}]',
    'negative identifier' => '[{"pm_id":-1,"name":"app"}]',
    'fractional identifier' => '[{"pm_id":0.5,"name":"app"}]',
    'string identifier' => '[{"pm_id":"0","name":"app"}]',
    'boolean identifier' => '[{"pm_id":true,"name":"app"}]',
    'unsafe JavaScript integer' => '[{"pm_id":9007199254740992,"name":"app"}]',
    'duplicate identifier' => '[{"pm_id":0,"name":"first"},{"pm_id":0,"name":"second"}]',
    'missing name' => '[{"pm_id":0}]',
    'invalid environment' => '[{"pm_id":0,"name":"app","pm2_env":[]}]',
    'invalid namespace' => '[{"pm_id":0,"name":"app","pm2_env":{"namespace":false}}]',
    'inconsistent name' => '[{"pm_id":0,"name":"app","pm2_env":{"name":"other"}}]',
    'invalid executable path' => '[{"pm_id":0,"name":"app","pm2_env":{"pm_exec_path":[]}}]',
    'later invalid entry' => '[{"pm_id":0,"name":"valid"},{"pm_id":"all","name":"invalid"}]',
]);

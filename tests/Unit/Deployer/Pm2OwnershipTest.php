<?php

it('rejects ambiguous numeric PM2 names or namespaces before deleting IDs', function (array $foreign) {
    $packageRoot = dirname(__DIR__, 3);
    $processes = [['pm_id' => 0, 'name' => 'owned'], $foreign];
    $pm2 = installFakePm2Binary($this->directory, $this->files, json_encode($processes, JSON_THROW_ON_ERROR));
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('a process name or namespace matches an ID')
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe('jlist');
})->with([
    'name collision' => [['pm_id' => 1, 'name' => '0', 'pm2_env' => ['namespace' => 'other']]],
    'namespace collision' => [['pm_id' => 1, 'name' => 'other-app', 'pm2_env' => ['namespace' => '0']]],
]);

it('rejects application names already owned by an untouched namespace', function (bool $cleanupLegacy, string $foreignNamespace, string $ecosystem) {
    $packageRoot = dirname(__DIR__, 3);
    $processes = [['pm_id' => 0, 'name' => 'worker', 'pm2_env' => ['namespace' => $foreignNamespace]]];
    $pm2 = installFakePm2Binary($this->directory, $this->files, json_encode($processes, JSON_THROW_ON_ERROR));
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_cleanup_legacy' => $cleanupLegacy], ecosystem: $ecosystem);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('name is already used by a process outside the selected namespace')
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe('jlist');
})->with([
    'foreign explicit name' => [true, 'other', "module.exports = { apps: [{ name: 'worker', script: 'app.js' }] };"],
    'foreign inferred name' => [true, 'other', "module.exports = { apps: [{ script: 'worker.js' }] };"],
    'preserved legacy name' => [false, 'default', "module.exports = { apps: [{ name: 'worker', script: 'app.js' }] };"],
]);

it('rejects ecosystem namespaces that would escape application ownership', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"old"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, ecosystem: "module.exports = { apps: [{ name: 'worker', script: 'app.js', namespace: 'other' }] };");
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('namespace must match pm2_namespace or be omitted')
        ->and($this->files->exists($this->directory.'/pm2.log'))->toBeFalse();
});

it('rejects invalid PM2 ownership configuration before running PM2', function (array $configuration) {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: $configuration);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($this->files->exists($this->directory.'/pm2.log'))->toBeFalse();
})->with([
    'empty namespace' => [['pm2_namespace' => '']],
    'blank namespace' => [['pm2_namespace' => '  ']],
    'nonstring namespace' => [['pm2_namespace' => true]],
    'null byte namespace' => [['pm2_namespace' => "app\0other"]],
    'nonboolean cleanup' => [['pm2_cleanup_legacy' => 'false']],
]);

it('quotes PM2 paths and namespaces as individual shell arguments', function () {
    $packageRoot = dirname(__DIR__, 3);
    $directory = $this->directory."/path with 'quotes'; echo unsafe";
    $this->files->ensureDirectoryExists($directory);
    $namespace = '--all; touch '.$this->directory."/injected; echo 'quoted' \$(touch ".$this->directory.'/injected)';
    $pm2 = installFakePm2Binary($directory, $this->files, '[]');
    $recipe = installPm2TaskRecipe($directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => $namespace]);
    $process = runPm2TaskRecipe($packageRoot, $directory, $recipe);
    $arguments = array_map(fn ($line) => json_decode($line, true), preg_split('/\R/', trim($this->files->get($directory.'/pm2-arguments.jsonl'))));

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and($arguments)->toBe([
            ['jlist'],
            ['start', $directory.'/release/ecosystem.config.cjs', '--namespace='.$namespace, '--update-env'],
            ['save', '--force'],
        ])
        ->and($this->files->exists($this->directory.'/injected'))->toBeFalse();
});

it('rejects executable paths that PM2 would interpret as a numeric deletion target', function () {
    $packageRoot = dirname(__DIR__, 3);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[]');
    $ecosystem = <<<'JS'
const fs = require('fs');
const path = require('path');
const cwd = process.cwd();
const processes = [
    {pm_id: 0, name: 'owned'},
    {pm_id: 1, name: 'foreign', pm2_env: {namespace: 'other', pm_exec_path: cwd + '/0'}},
];
fs.writeFileSync(path.join(__dirname, '..', 'processes.json'), JSON.stringify(processes));
fs.writeFileSync(path.join(__dirname, '..', 'node-cwd.json'), JSON.stringify({cwd, processes}));
module.exports = {apps: []};
JS;
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, ecosystem: $ecosystem);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);
    $fixture = $this->files->json($this->directory.'/node-cwd.json');
    $diagnostics = json_encode([
        'phpReleasePath' => realpath($this->directory.'/release'),
        'nodeCwd' => $fixture['cwd'],
        'output' => $process->getOutput().$process->getErrorOutput(),
    ], JSON_THROW_ON_ERROR);

    expect($fixture['processes'])->toBe([
        ['pm_id' => 0, 'name' => 'owned'],
        ['pm_id' => 1, 'name' => 'foreign', 'pm2_env' => ['namespace' => 'other', 'pm_exec_path' => $fixture['cwd'].'/0']],
    ])
        ->and($process->isSuccessful())->toBeFalse($diagnostics)
        ->and($process->getOutput())->toContain('an executable path resolves to it')
        ->and($this->files->json($this->directory.'/processes.json'))->toBe($fixture['processes'])
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe('jlist');
});

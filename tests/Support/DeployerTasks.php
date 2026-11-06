<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

function quotePosixShellArgument(string $argument): string
{
    return "'".str_replace("'", "'\"'\"'", $argument)."'";
}

function installFakePm2Binary(string $directory, Filesystem $files, string $processList): string
{
    $binary = str_replace('\\', '/', $directory.'/bin/pm2');
    $files->ensureDirectoryExists($directory.'/bin');
    $files->ensureDirectoryExists($directory.'/lib');
    $files->put($directory.'/processes.json', $processList);
    $files->put($directory.'/dump.json', $processList);
    $script = <<<'PHP'
<?php
$directory = dirname(__DIR__);
$arguments = array_slice($argv, 1);
file_put_contents($directory.'/pm2.log', implode(' ', $arguments)."\n", FILE_APPEND);
file_put_contents($directory.'/pm2-arguments.jsonl', json_encode($arguments)."\n", FILE_APPEND);
$command = $arguments[0];
$failures = is_file($directory.'/pm2-fail.json') ? json_decode(file_get_contents($directory.'/pm2-fail.json'), true) : [];
if (isset($failures[$command.':'.($arguments[1] ?? '')]) || isset($failures[$command])) {
    fwrite(STDERR, 'Simulated PM2 failure');
    exit(42);
}
$raw = file_get_contents($directory.'/processes.json');
if ($command === 'jlist') {
    echo $raw;
    exit;
}
$processes = json_decode($raw, true);
if ($command === 'delete') {
    $target = $arguments[1];
    $names = array_filter($processes, fn ($process) => ($process['pm2_env']['name'] ?? $process['name'] ?? '') === $target || ($process['pm2_env']['pm_exec_path'] ?? '') === getcwd().'/'.$target);
    $namespaces = array_filter($processes, fn ($process) => ($process['pm2_env']['namespace'] ?? null) === $target);
    $ids = array_column($names ?: $namespaces, 'pm_id');
    $processes = array_values(array_filter($processes, fn ($process) => $target !== 'all' && !in_array($process['pm_id'], $ids ?: [(int) $target], true)));
    file_put_contents($directory.'/processes.json', json_encode($processes));
}
if ($command === 'start') {
    $namespaceArguments = array_values(array_filter($arguments, fn ($argument) => str_starts_with($argument, '--namespace=')));
    $namespace = $namespaceArguments === [] ? 'default' : substr($namespaceArguments[0], strlen('--namespace='));
    $processes[] = ['pm_id' => 100, 'name' => 'new-app', 'pm2_env' => ['namespace' => $namespace]];
    file_put_contents($directory.'/processes.json', json_encode($processes));
    if ($failures['partial_start'] ?? false) {
        fwrite(STDERR, 'Simulated partial PM2 startup failure');
        exit(42);
    }
}
if ($command === 'save' && ($processes !== [] || in_array('--force', $arguments, true))) {
    file_put_contents($directory.'/dump.json', json_encode($processes));
}
PHP;
    $files->put($binary.'.php', $script);
    $files->put($binary, "#!/bin/sh\nexec ".quotePosixShellArgument(PHP_BINARY).' '.quotePosixShellArgument($binary.'.php')." \"\$@\"\n");
    $files->put($directory.'/lib/Common.js', <<<'JS'
const fs = require('fs');
const path = require('path');
const vm = require('vm');
exports.parseConfig = (contents, file) => file.includes('.json')
    ? vm.runInThisContext('(' + contents + ')')
    : require(file);
exports.verifyConfs = configurations => [].concat(configurations).map(app => {
    if (!app || typeof app !== 'object') throw new Error('Invalid PM2 application');
    if (!app.script) app.script = app.cmd || app.command;
    if (!app.name && app.script) {
        app.name = path.basename(app.script);
        const dot = app.name.lastIndexOf('.');
        if (dot > 0) app.name = app.name.slice(0, dot);
    }
    return app;
});
JS);
    chmod($binary, 0755);

    return $binary;
}

function installFakeGitBinary(string $directory, Filesystem $files, string $references): string
{
    $binary = str_replace('\\', '/', $directory.'/git');
    $log = str_replace('\\', '/', $directory.'/git.log');
    $script = "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".quotePosixShellArgument($log)."\nprintf '%s\\n' ".quotePosixShellArgument($references)."\n";

    $files->put($binary, $script);
    chmod($binary, 0755);

    return str_replace('\\', '/', $binary);
}

function installGitTagTaskRecipe(
    string $directory,
    Filesystem $files,
    string $contribPath,
    string $gitBinary,
): string {
    $recipe = $directory.'/deploy.php';
    $files->put($recipe, "<?php\n\nnamespace Deployer;\n\nrequire 'recipe/common.php';\nimport(".var_export($contribPath, true).");\n\nlocalhost('local');\nset('repository', 'git@example.com:owner/project.git');\nset('branch', '2.x');\nset('repository_tag_limit', 2);\nset('bin/git', ".var_export($gitBinary, true).");\ntask('verify:selected-tag', static function (): void { writeln('SELECTED_TAG='.get('branch')); writeln('SELECTED_TARGET='.get('target')); });\ntask('verify:git-tag', ['repository:tag', 'verify:selected-tag']);\n");

    return $recipe;
}

function installPm2TaskRecipe(
    string $directory,
    Filesystem $files,
    string $contribPath,
    string $pm2Binary,
    bool $configureFile = true,
    array $configuration = [],
    string $ecosystem = "module.exports = { apps: [] };\n",
): string {
    $releasePath = $directory.'/release';
    $recipe = $directory.'/deploy.php';
    $pm2Config = $configureFile
        ? "set('pm2_config_file', 'ecosystem.config.cjs');\n"
        : '';

    $files->ensureDirectoryExists($releasePath);
    $files->put($releasePath.'/ecosystem.config.cjs', $ecosystem);
    $configuration = array_replace(['deploy_path' => '/var/www/current-app'], $configuration);

    foreach ($configuration as $key => $value) {
        $pm2Config .= 'set('.var_export($key, true).', '.var_export($value, true).");\n";
    }
    $files->put($recipe, "<?php\n\nnamespace Deployer;\n\nrequire 'recipe/common.php';\nimport(".var_export($contribPath, true).");\n\nlocalhost('local');\nset('release_path', ".var_export($releasePath, true).");\nset('bin/pm2', ".var_export($pm2Binary, true).");\n{$pm2Config}\ntask('verify:pm2', ['pm2:config']);\n");

    return $recipe;
}

function runPm2TaskRecipe(string $packageRoot, string $directory, string $recipe): Process
{
    $process = new Process([
        PHP_BINARY,
        $packageRoot.'/vendor/bin/dep',
        '--file='.$recipe,
        'verify:pm2',
        'local',
        '--no-interaction',
    ], $directory, [
        'DEBIAN_FRONTEND' => false,
    ]);
    $process->run();

    return $process;
}

<?php

use Deployer\Deployer;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;

use function Deployer\get;
use function Deployer\import;
use function Deployer\task;
use function Illuminate\Filesystem\join_paths;

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
    ], $directory);
    $process->run();

    return $process;
}

beforeEach(function () {
    $this->files = new Filesystem;
    $this->directory = temporaryTestDirectory('maintainer-deployer-');
    $this->originalIdentityFile = getenv('MAINTAINER_SSH_IDENTITY_FILE');
    $this->files->makeDirectory($this->directory, recursive: true);
    putenv('MAINTAINER_SSH_IDENTITY_FILE');
});

afterEach(function () {
    Deployer::resetInstance();
    deleteTemporaryDirectory($this->directory);

    if ($this->originalIdentityFile === false) {
        putenv('MAINTAINER_SSH_IDENTITY_FILE');
    } else {
        putenv('MAINTAINER_SSH_IDENTITY_FILE='.$this->originalIdentityFile);
    }
});

it('imports the Maintainer Deployer contribution recipe', function () {
    new Deployer(new Application);
    task('deploy', static function (): void {});
    task('deploy:unlock', static function (): void {});
    task('deploy:is_locked', static function (): void {});

    import(dirname(__DIR__, 2).'/app/Deployer/contrib.php');

    expect(get('recipes'))->toBe(['maintainer'])
        ->and(get('identity_file', null))->toBeNull()
        ->and(get('repository_tag_limit'))->toBe(10)
        ->and(task('repository:tag')->getDescription())
        ->toBe('Select a Git tag to deploy')
        ->and(task('pm2:config')->getDescription())
        ->toBe('Apply the authoritative PM2 ecosystem configuration');
});

it('configures the temporary Maintainer SSH identity for Deployer hosts', function () {
    $identityFile = join_paths($this->directory, 'identity');
    $this->files->put($identityFile, 'private key');
    putenv('MAINTAINER_SSH_IDENTITY_FILE='.$identityFile);
    new Deployer(new Application);
    task('deploy', static function (): void {});
    task('deploy:unlock', static function (): void {});
    task('deploy:is_locked', static function (): void {});

    import(dirname(__DIR__, 2).'/app/Deployer/contrib.php');

    $notice = task('maintainer:ssh:identity');

    expect(get('identity_file'))->toBe($identityFile)
        ->and($notice->isOnce())->toBeTrue()
        ->and($notice->isHidden())->toBeTrue()
        ->and(task('deploy')->getBefore())->toContain('maintainer:ssh:identity')
        ->and(task('deploy:unlock')->getBefore())->toContain('maintainer:ssh:identity');
});

it('configures the Maintainer SSH identity without relying on task hooks', function () {
    $packageRoot = dirname(__DIR__, 2);
    $identityFile = $this->directory.'/identity';
    $recipe = $this->directory.'/deploy.php';
    $this->files->put($identityFile, 'private key');
    $this->files->put($recipe, "<?php\n\nnamespace Deployer;\n\nrequire 'recipe/common.php';\nimport(".var_export($packageRoot.'/app/Deployer/contrib.php', true).");\nlocalhost('local');\ntask('verify:identity', static function (): void { writeln('IDENTITY='.get('identity_file')); });\n");
    $process = new Process([
        PHP_BINARY,
        $packageRoot.'/vendor/bin/dep',
        '--file='.$recipe,
        'verify:identity',
        'local',
        '--no-hooks',
        '--no-interaction',
    ], $this->directory, [
        'MAINTAINER_SSH_IDENTITY_FILE' => $identityFile,
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('IDENTITY='.$identityFile)
        ->not->toContain('Using SSH identity provided by Maintainer');
});

it('loads the installed contribution recipe from the published deploy file', function () {
    $packageRoot = dirname(__DIR__, 2);
    $this->files->copy($packageRoot.'/resources/deploy.php', $this->directory.'/deploy.php');

    $process = new Process([
        PHP_BINARY,
        $packageRoot.'/vendor/bin/dep',
        '--file='.$this->directory.'/deploy.php',
        'list',
        '--raw',
    ], $this->directory, [
        'MAINTAINER_CONTRIB' => $packageRoot.'/app/Deployer/contrib.php',
        'MAINTAINER_SSH_IDENTITY_FILE' => $this->directory.'/identity',
    ]);
    $process->mustRun();

    expect($process->getOutput())
        ->toContain('artisan:cache:clear')
        ->toContain('repository:tag')
        ->toContain('npm:build')
        ->toContain('pm2:config');
});

it('selects the newest tag from the configured branch major', function () {
    $packageRoot = dirname(__DIR__, 2);
    $references = "aaaaaaaa\trefs/tags/v1.9.0\nbbbbbbbb\trefs/tags/v2.0.0-beta\ncccccccc\trefs/tags/v2.0.0\ndddddddd\trefs/tags/v2.1.0-alpha";
    $git = installFakeGitBinary($this->directory, $this->files, $references);
    $recipe = installGitTagTaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $git);
    $process = new Process([
        PHP_BINARY,
        $packageRoot.'/vendor/bin/dep',
        '--file='.$recipe,
        'verify:git-tag',
        'local',
    ], $this->directory);
    $process->setInput("\n");
    $process->setTimeout(15);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($process->getOutput())->toContain('SELECTED_TAG=v2.1.0-alpha')
        ->and($process->getOutput())->toContain('SELECTED_TARGET=v2.1.0-alpha')
        ->and(trim($this->files->get($this->directory.'/git.log')))
        ->toBe('ls-remote --tags --refs git@example.com:owner/project.git');
});

it('does not query tags when the tag option is already provided', function () {
    $packageRoot = dirname(__DIR__, 2);
    $git = installFakeGitBinary($this->directory, $this->files, 'aaaaaaaa refs/tags/v2.0.0');
    $recipe = installGitTagTaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $git);
    $process = new Process([
        PHP_BINARY,
        $packageRoot.'/vendor/bin/dep',
        '--file='.$recipe,
        'verify:git-tag',
        'local',
        '--tag=v2.0.0',
        '--no-interaction',
    ], $this->directory);
    $process->run();

    expect($process->isSuccessful())->toBeTrue()
        ->and($this->files->exists($this->directory.'/git.log'))->toBeFalse();
});

it('replaces existing PM2 processes with the configured ecosystem file', function () {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
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

it('requires a PM2 ecosystem configuration file', function () {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
    $missingPm2 = str_replace('\\', '/', $this->directory.'/missing-pm2');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $missingPm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('PM2 is not installed or is not available');
});

it('rejects an invalid PM2 JSON process list', function () {
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, 'not-json');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getErrorOutput().$process->getOutput())
        ->toContain('pm2 jlist returned invalid JSON')
        ->and(trim($this->files->get($this->directory.'/pm2.log')))
        ->toBe('jlist');
});

it('preserves other namespaces while replacing its own and legacy processes', function () {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
    $processes = '[{"pm_id":0,"name":"current","pm2_env":{"namespace":"custom"}},{"pm_id":1,"name":"legacy","pm2_env":{"namespace":"default"}},{"pm_id":2,"name":"older"}]';
    $pm2 = installFakePm2Binary($this->directory, $this->files, $processes);
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => 'custom', 'pm2_cleanup_legacy' => false]);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1, 2, 100]);
});

it('replaces an explicitly selected default namespace even with legacy cleanup disabled', function () {
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"current","pm2_env":{"namespace":"default"}},{"pm_id":1,"name":"older"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => 'default', 'pm2_cleanup_legacy' => false]);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1, 100]);
});

it('persists successful deletions even when the subsequent ecosystem start fails', function () {
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"old-app","pm2_env":{"namespace":"default"}}]');
    $this->files->put($this->directory.'/pm2-fail.json', '{"start":true}');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(json_decode($this->files->get($this->directory.'/dump.json'), true))->toBe([])
        ->and($this->files->get($this->directory.'/pm2.log'))->toContain("delete 0\nsave --force\nstart ");
});

it('persists each deletion before another deletion fails', function () {
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"first"},{"pm_id":1,"name":"second"}]');
    $this->files->put($this->directory.'/pm2-fail.json', '{"delete:1":true}');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(array_column(json_decode($this->files->get($this->directory.'/dump.json'), true), 'pm_id'))->toBe([1])
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe("jlist\ndelete 0\nsave --force\ndelete 1");
});

it('stops before starting the ecosystem if persisting a deletion fails', function () {
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"first"}]');
    $this->files->put($this->directory.'/pm2-fail.json', '{"save":true}');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe("jlist\ndelete 0\nsave --force");
});

it('rejects malformed PM2 process lists before changing any processes', function (string $processList) {
    $packageRoot = dirname(__DIR__, 2);
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

it('rejects ambiguous numeric PM2 names or namespaces before deleting IDs', function (array $foreign) {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"old"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, ecosystem: "module.exports = { apps: [{ name: 'worker', script: 'app.js', namespace: 'other' }] };");
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('namespace must match pm2_namespace or be omitted')
        ->and($this->files->exists($this->directory.'/pm2.log'))->toBeFalse();
});

it('rejects invalid PM2 ownership configuration before running PM2', function (array $configuration) {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
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

it('runs explicit server maintenance tasks with noninteractive privilege handling', function (string $taskName, array $expectedArguments, bool $apt) {
    $packageRoot = dirname(__DIR__, 2);
    $sudo = $this->directory.'/fake sudo';
    $binary = $this->directory.'/fake operation';
    $sudoLog = $this->directory.'/sudo.log';
    $operationLog = $this->directory.'/operation.log';
    $environmentLog = $this->directory.'/environment.log';
    $this->files->put($sudo, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".quotePosixShellArgument($sudoLog)."\n[ \"\$1\" = '-n' ] || exit 90\nshift\n[ \"\$1\" = '--' ] || exit 91\nshift\nexec \"\$@\"\n");
    $this->files->put($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".quotePosixShellArgument($operationLog)."\nprintf '%s' \"\$DEBIAN_FRONTEND\" > ".quotePosixShellArgument($environmentLog)."\n");
    chmod($sudo, 0755);
    chmod($binary, 0755);
    $recipe = $this->directory.'/deploy.php';
    $this->files->put($recipe, "<?php\nnamespace Deployer;\nrequire 'recipe/common.php';\nimport(".var_export($packageRoot.'/app/Deployer/contrib.php', true).");\nlocalhost('local');\nset('bin/sudo', ".var_export($sudo, true).");\nset('bin/apt-get', ".var_export($binary, true).");\nset('bin/reboot', ".var_export($binary, true).");\ntask('verify:pm2', [".var_export($taskName, true)."]);\n");
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);
    $sudoArguments = ['-n', '--', ...($apt ? ['env', 'DEBIAN_FRONTEND=noninteractive'] : []), $binary, ...$expectedArguments];

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(explode("\n", rtrim($this->files->get($sudoLog), "\n")))->toBe($sudoArguments)
        ->and(trim($this->files->get($operationLog)))->toBe(implode("\n", $expectedArguments))
        ->and($this->files->get($environmentLog))->toBe($apt ? 'noninteractive' : '');
})->with([
    'APT update' => ['server:apt:update', ['update'], true],
    'APT upgrade' => ['server:apt:upgrade', ['--yes', '-o', 'Dpkg::Options::=--force-confold', 'upgrade'], true],
    'reboot' => ['server:reboot', [], false],
]);

it('propagates server maintenance failures without retrying sudo interactively', function (string $taskName, bool $failSudo) {
    $packageRoot = dirname(__DIR__, 2);
    $sudo = $this->directory.'/sudo';
    $binary = $this->directory.'/operation';
    $log = $this->directory.'/sudo.log';
    $script = "#!/bin/sh\nprintf 'called\\n' >> ".quotePosixShellArgument($log)."\n";
    $script .= $failSudo ? "exit 42\n" : "shift\nshift\nexec \"\$@\"\n";
    $this->files->put($sudo, $script);
    $this->files->put($binary, "#!/bin/sh\nexit 43\n");
    chmod($sudo, 0755);
    chmod($binary, 0755);
    $recipe = $this->directory.'/deploy.php';
    $this->files->put($recipe, "<?php\nnamespace Deployer;\nrequire 'recipe/common.php';\nimport(".var_export($packageRoot.'/app/Deployer/contrib.php', true).");\nlocalhost('local');\nset('bin/sudo', ".var_export($sudo, true).");\nset('bin/apt-get', ".var_export($binary, true).");\nset('bin/reboot', ".var_export($binary, true).");\ntask('verify:pm2', [".var_export($taskName, true)."]);\n");
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($this->files->get($log))->toBe("called\n")
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('[sudo] password');
})->with([
    'privilege failure' => ['server:apt:update', true],
    'APT update failure' => ['server:apt:update', false],
    'APT upgrade failure' => ['server:apt:upgrade', false],
    'reboot failure' => ['server:reboot', false],
]);

it('persists applications created by a partially failed ecosystem start', function (bool $failSave) {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"pm2-logrotate","pm2_env":{"namespace":"default","pmx_module":true}},{"pm_id":1,"name":"old-app"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/processes.json'), true), 'pm_id'))->toBe([0, 100]);
});

it('treats the reserved all namespace as data rather than a global deletion target', function () {
    $packageRoot = dirname(__DIR__, 2);
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"pm_id":0,"name":"app","pm2_env":{"namespace":"all"}},{"pm_id":1,"name":"other","pm2_env":{"namespace":"other"}}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2, configuration: ['pm2_namespace' => 'all']);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and(array_column(json_decode($this->files->get($this->directory.'/processes.json'), true), 'pm_id'))->toBe([1, 100])
        ->and($this->files->get($this->directory.'/pm2.log'))->not->toContain('delete all');
});

it('fails before mutations when the ecosystem or inspection runtime is unavailable', function (string $failure) {
    $packageRoot = dirname(__DIR__, 2);
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
    $packageRoot = dirname(__DIR__, 2);
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

it('rejects executable paths that PM2 would interpret as a numeric deletion target', function () {
    $packageRoot = dirname(__DIR__, 2);
    $processes = [
        ['pm_id' => 0, 'name' => 'owned'],
        ['pm_id' => 1, 'name' => 'foreign', 'pm2_env' => ['namespace' => 'other', 'pm_exec_path' => realpath($this->directory).'/release/0']],
    ];
    $pm2 = installFakePm2Binary($this->directory, $this->files, json_encode($processes, JSON_THROW_ON_ERROR));
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);
    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput())->toContain('an executable path resolves to it')
        ->and(trim($this->files->get($this->directory.'/pm2.log')))->toBe('jlist');
});

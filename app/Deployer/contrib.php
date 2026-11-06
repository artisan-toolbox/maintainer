<?php

declare(strict_types=1);

namespace Deployer;

use ArtisanToolbox\Maintainer\Deployer\GitTagSelector;
use JsonException;

add('recipes', ['maintainer']);

$maintainerIdentityFile = getenv('MAINTAINER_SSH_IDENTITY_FILE');

if (is_string($maintainerIdentityFile) && is_file($maintainerIdentityFile)) {
    set('identity_file', $maintainerIdentityFile);
}

unset($maintainerIdentityFile);

/*
|--------------------------------------------------------------------------
| Maintainer Hooks
|--------------------------------------------------------------------------
*/
before('deploy', 'maintainer:ssh:identity');
before('deploy:unlock', 'maintainer:ssh:identity');
before('deploy:is_locked', 'maintainer:ssh:identity');

/*
|--------------------------------------------------------------------------
| Maintainer Tasks
|--------------------------------------------------------------------------
*/
task('maintainer:ssh:identity', static function (): void {
    $identityFile = getenv('MAINTAINER_SSH_IDENTITY_FILE');

    if (is_string($identityFile) && is_file($identityFile) && get('identity_file', null) === $identityFile) {
        info('Using SSH identity provided by Maintainer');
    }
})->once()->hidden();

set('repository_tag_limit', 10);

desc('Select a Git tag to deploy');
task('repository:tag', static function (): void {
    $tagOption = input()->hasOption('tag') ? input()->getOption('tag') : null;

    if (is_string($tagOption) && $tagOption !== '') {
        return;
    }

    $repository = get('repository', '');

    if (! is_string($repository) || $repository === '') {
        throw error('The repository configuration must contain the Git repository URL.');
    }

    $git = get('bin/git', 'git');

    if (! is_string($git) || $git === '') {
        throw error('The bin/git configuration must contain the Git executable.');
    }

    $configuredLimit = get('repository_tag_limit', 10);
    $limit = is_int($configuredLimit)
        ? $configuredLimit
        : (is_string($configuredLimit) && ctype_digit($configuredLimit) ? (int) $configuredLimit : 0);

    if ($limit < 1) {
        throw error('The repository_tag_limit configuration must be an integer greater than zero.');
    }

    $branchOption = input()->hasOption('branch') ? input()->getOption('branch') : null;
    $branch = is_string($branchOption) && $branchOption !== ''
        ? $branchOption
        : get('branch', '');

    $branch = is_string($branch) ? $branch : '';
    $references = runLocally(
        quote($git).' ls-remote --tags --refs '.quote($repository),
        env: [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_SSH_COMMAND' => get('git_ssh_command', 'ssh -o StrictHostKeyChecking=accept-new'),
        ],
    );
    $tags = GitTagSelector::fromRemoteReferences($references, $branch, $limit);

    if ($tags === []) {
        $major = GitTagSelector::majorFromBranch($branch);
        $scope = $major === null ? '' : " for major {$major}";

        throw error("No Git tags{$scope} were found in the configured repository.");
    }

    $selectedTag = askChoice('Choose a Git tag', $tags, 0);

    if (! is_string($selectedTag) || $selectedTag === '') {
        throw error('The selected Git tag is invalid.');
    }

    set('branch', $selectedTag);
    set('target', $selectedTag);
});

set('bin/pm2', 'pm2');
set('bin/node', 'node');
set('pm2_namespace', static fn () => get('deploy_path'));
set('pm2_cleanup_legacy', true);

desc('Apply the authoritative PM2 ecosystem configuration');
task('pm2:config', static function (): void {
    $configFile = has('pm2_config_file') ? get('pm2_config_file') : null;

    if (! is_string($configFile) || $configFile === '') {
        throw error('The pm2_config_file configuration must contain the ecosystem file path.');
    }

    $pm2 = get('bin/pm2', 'pm2');

    if (! is_string($pm2) || $pm2 === '') {
        throw error('The bin/pm2 configuration must contain the PM2 executable.');
    }

    $releasePath = get('release_path');

    if (! is_string($releasePath) || $releasePath === '') {
        throw error('The release_path configuration must contain the current release directory.');
    }

    $namespace = get('pm2_namespace');

    if (! is_string($namespace) || trim($namespace) === '' || str_contains($namespace, "\0")) {
        throw error('The pm2_namespace configuration must contain a non-empty application namespace.');
    }

    $cleanupLegacy = get('pm2_cleanup_legacy');

    if (! is_bool($cleanupLegacy)) {
        throw error('The pm2_cleanup_legacy configuration must be a boolean.');
    }

    if (! test('command -v '.quote($pm2).' >/dev/null 2>&1')) {
        throw error("PM2 is not installed or is not available as {$pm2} on {{alias}}.");
    }

    $configPath = str_starts_with($configFile, '/')
        ? $configFile
        : rtrim($releasePath, '/').'/'.$configFile;

    if (! test('[ -f '.quote($configPath).' ]')) {
        throw error("The PM2 ecosystem file {$configFile} does not exist in {$releasePath}.");
    }

    $node = get('bin/node');

    if (! is_string($node) || $node === '') {
        throw error('The bin/node configuration must contain the Node.js executable.');
    }

    $inspectEcosystem = <<<'JS_WRAP'
    const fs = require('fs');
    const path = require('path');
    const pm2 = fs.realpathSync(process.argv[1]);
    const common = require(path.resolve(path.dirname(pm2), '../lib/Common.js'));
    const file = process.argv[2];
    const config = common.parseConfig(fs.readFileSync(file), file);
    if (!config || typeof config !== 'object') throw new Error('The ecosystem must contain application configuration.');
    const apps = common.verifyConfs(config.apps || config.pm2 || config);
    if (apps instanceof Error) throw apps;
    for (const app of config.static || []) apps.push({name: app.name || `static-page-server-${app.port}`});
    process.stdout.write(JSON.stringify({cwd: process.cwd(), apps: apps.map(app => ({name: app.name, namespace: app.namespace}))}));
    JS_WRAP;
    $pm2Path = run('command -v '.quote($pm2));

    try {
        $ecosystem = json_decode(run(
            quote($node).' -e '.quote($inspectEcosystem).' -- '.quote($pm2Path).' '.quote($configPath),
            cwd: $releasePath,
        ), flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw error('Unable to inspect the PM2 ecosystem configuration: '.$exception->getMessage());
    }

    if (! $ecosystem instanceof \stdClass || ! isset($ecosystem->cwd) || ! is_string($ecosystem->cwd)
        || ! isset($ecosystem->apps) || ! is_array($ecosystem->apps) || ! array_is_list($ecosystem->apps)
    ) {
        throw error('Unable to inspect the PM2 ecosystem application list.');
    }

    $applicationNames = [];

    foreach ($ecosystem->apps as $application) {
        if (! $application instanceof \stdClass || ! isset($application->name)
            || ! is_string($application->name) || $application->name === ''
        ) {
            throw error('Every PM2 ecosystem application must have a valid name or script.');
        }

        if (isset($application->namespace) && $application->namespace !== '' && $application->namespace !== $namespace) {
            throw error('Every PM2 ecosystem application namespace must match pm2_namespace or be omitted.');
        }

        $applicationNames[] = $application->name;
    }

    try {
        $processes = json_decode(run(quote($pm2).' jlist'), flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw error('Unable to inspect PM2 processes because pm2 jlist returned invalid JSON: '.$exception->getMessage());
    }

    if (! is_array($processes) || ! array_is_list($processes)) {
        throw error('Unable to inspect PM2 processes because pm2 jlist did not return a JSON list.');
    }

    $selectedIds = [];
    $seenIds = [];

    foreach ($processes as $process) {
        if (! $process instanceof \stdClass
            || ! isset($process->pm_id) || ! is_int($process->pm_id) || $process->pm_id < 0 || $process->pm_id > 9007199254740991
            || isset($seenIds[$process->pm_id])
            || ! isset($process->name) || ! is_string($process->name) || $process->name === ''
            || (property_exists($process, 'pm2_env') && ! $process->pm2_env instanceof \stdClass)
        ) {
            throw error('Unable to inspect PM2 processes because pm2 jlist returned an invalid process entry.');
        }

        $processNamespace = $process->pm2_env->namespace ?? null;

        if ($processNamespace !== null && ! is_string($processNamespace)) {
            throw error('Unable to inspect PM2 processes because pm2 jlist returned an invalid process namespace.');
        }

        if ((isset($process->pm2_env->name) && $process->pm2_env->name !== $process->name)
            || (isset($process->pm2_env->pm_exec_path) && ! is_string($process->pm2_env->pm_exec_path))
        ) {
            throw error('Unable to inspect PM2 processes because pm2 jlist returned inconsistent process metadata.');
        }

        $seenIds[$process->pm_id] = true;

        if (($process->pm2_env->pmx_module ?? false) !== true
            && ($processNamespace === $namespace
                || ($cleanupLegacy && in_array($processNamespace, [null, '', 'default'], true)))
        ) {
            $selectedIds[] = $process->pm_id;
        } elseif (in_array($process->name, $applicationNames, true)) {
            throw error('A PM2 ecosystem application name is already used by a process outside the selected namespace.');
        }
    }

    foreach ($processes as $process) {
        foreach ($selectedIds as $id) {
            if ($process->name === (string) $id || ($process->pm2_env->namespace ?? null) === (string) $id
                || ($process->pm2_env->pm_exec_path ?? null) === rtrim($ecosystem->cwd, '/').'/'.$id
            ) {
                throw error('Unable to safely delete PM2 process IDs because a process name or namespace matches an ID, or an executable path resolves to it.');
            }
        }
    }

    foreach ($selectedIds as $id) {
        info("Removing PM2 process {$id} before applying the ecosystem configuration.");
        run(quote($pm2).' delete '.quote($id), cwd: $ecosystem->cwd);
        run(quote($pm2).' save --force');
    }

    try {
        run(quote($pm2).' start '.quote($configPath).' --namespace='.quote($namespace).' --update-env', cwd: $releasePath);
    } catch (\Throwable $exception) {
        try {
            run(quote($pm2).' save --force');
        } catch (\Throwable $saveException) {
            warning('Unable to persist the PM2 process list after the failed start: '.$saveException->getMessage());
        }

        throw $exception;
    }

    run(quote($pm2).' save --force');
});

set('bin/sudo', 'sudo');
set('bin/apt-get', 'apt-get');
set('bin/reboot', 'reboot');

foreach ([
    'server:apt:update' => ['Refresh the server APT package index', 'bin/apt-get', ' update'],
    'server:apt:upgrade' => ['Upgrade installed server APT packages', 'bin/apt-get', ' --yes -o Dpkg::Options::=--force-confold upgrade'],
    'server:reboot' => ['Reboot the server', 'bin/reboot', ''],
] as $name => [$description, $binaryKey, $arguments]) {
    desc($description);
    task($name, static function () use ($binaryKey, $arguments): void {
        $sudo = get('bin/sudo');
        $binary = get($binaryKey);

        if (! is_string($sudo) || $sudo === '' || ! is_string($binary) || $binary === '') {
            throw error("The bin/sudo and {$binaryKey} configurations must contain executable paths.");
        }

        $environment = $binaryKey === 'bin/apt-get' ? 'env DEBIAN_FRONTEND=noninteractive ' : '';

        run('command '.quote($sudo).' -n -- '.$environment.quote($binary).$arguments, forceOutput: true);
    });
}

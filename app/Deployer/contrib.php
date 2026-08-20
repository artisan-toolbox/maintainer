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

    if (! test('command -v '.quote($pm2).' >/dev/null 2>&1')) {
        throw error("PM2 is not installed or is not available as {$pm2} on {{alias}}.");
    }

    $configPath = str_starts_with($configFile, '/')
        ? $configFile
        : rtrim($releasePath, '/').'/'.$configFile;

    if (! test('[ -f '.quote($configPath).' ]')) {
        throw error("The PM2 ecosystem file {$configFile} does not exist in {$releasePath}.");
    }

    try {
        $processes = json_decode(run(quote($pm2).' jlist'), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw error('Unable to inspect PM2 processes because pm2 jlist returned invalid JSON: '.$exception->getMessage());
    }

    if (! is_array($processes) || ! array_is_list($processes)) {
        throw error('Unable to inspect PM2 processes because pm2 jlist did not return a JSON list.');
    }

    if ($processes !== []) {
        info('Stopping '.count($processes).' existing PM2 process(es) before applying the ecosystem configuration.');
        run(quote($pm2).' delete all');
    }

    run(quote($pm2).' start '.quote($configFile).' --update-env', cwd: $releasePath);
    run(quote($pm2).' save');
});

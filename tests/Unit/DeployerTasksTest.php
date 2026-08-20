<?php

use Deployer\Deployer;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;

use function Deployer\get;
use function Deployer\import;
use function Deployer\task;
use function Illuminate\Filesystem\join_paths;

function installFakePm2Binary(string $directory, Filesystem $files, string $processList): string
{
    $windows = PHP_OS_FAMILY === 'Windows';
    $binary = $directory.'/pm2'.($windows ? '.bat' : '');
    $log = $directory.'/pm2.log';
    $script = $windows
        ? "@echo off\r\necho %*>>\"{$log}\"\r\nif \"%1\"==\"jlist\" echo {$processList}\r\nexit /b 0\r\n"
        : "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\nif [ \"\$1\" = 'jlist' ]; then\n    printf '%s\\n' ".escapeshellarg($processList)."\nfi\n";

    $files->put($binary, $script);
    chmod($binary, 0755);

    return str_replace('\\', '/', $binary);
}

function installFakeGitBinary(string $directory, Filesystem $files, string $references): string
{
    $windows = PHP_OS_FAMILY === 'Windows';
    $binary = $directory.'/git'.($windows ? '.bat' : '');
    $log = $directory.'/git.log';
    $script = $windows
        ? "@echo off\r\necho %*>>\"{$log}\"\r\necho {$references}\r\nexit /b 0\r\n"
        : "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".escapeshellarg($log)."\nprintf '%s\\n' ".escapeshellarg($references)."\n";

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
): string {
    $releasePath = $directory.'/release';
    $recipe = $directory.'/deploy.php';
    $pm2Config = $configureFile
        ? "set('pm2_config_file', 'ecosystem.config.cjs');\n"
        : '';

    $files->ensureDirectoryExists($releasePath);
    $files->put($releasePath.'/ecosystem.config.cjs', "module.exports = { apps: [] };\n");
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
    $pm2 = installFakePm2Binary($this->directory, $this->files, '[{"name":"old-app"}]');
    $recipe = installPm2TaskRecipe($this->directory, $this->files, $packageRoot.'/app/Deployer/contrib.php', $pm2);

    $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

    expect($process->isSuccessful())->toBeTrue()
        ->and(preg_split('/\R/', trim($this->files->get($this->directory.'/pm2.log'))))
        ->toBe([
            'jlist',
            'delete all',
            'start ecosystem.config.cjs --update-env',
            'save',
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
            'start ecosystem.config.cjs --update-env',
            'save',
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

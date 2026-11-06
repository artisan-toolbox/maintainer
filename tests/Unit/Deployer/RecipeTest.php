<?php

use Deployer\Deployer;
use Symfony\Component\Console\Application;
use Symfony\Component\Process\Process;

use function Deployer\get;
use function Deployer\import;
use function Deployer\task;
use function Illuminate\Filesystem\join_paths;

it('imports the Maintainer Deployer contribution recipe', function () {
    new Deployer(new Application);
    task('deploy', static function (): void {});
    task('deploy:unlock', static function (): void {});
    task('deploy:is_locked', static function (): void {});

    import(dirname(__DIR__, 3).'/app/Deployer/contrib.php');

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

    import(dirname(__DIR__, 3).'/app/Deployer/contrib.php');

    $notice = task('maintainer:ssh:identity');

    expect(get('identity_file'))->toBe($identityFile)
        ->and($notice->isOnce())->toBeTrue()
        ->and($notice->isHidden())->toBeTrue()
        ->and(task('deploy')->getBefore())->toContain('maintainer:ssh:identity')
        ->and(task('deploy:unlock')->getBefore())->toContain('maintainer:ssh:identity');
});

it('configures the Maintainer SSH identity without relying on task hooks', function () {
    $packageRoot = dirname(__DIR__, 3);
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
    $packageRoot = dirname(__DIR__, 3);
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
    $packageRoot = dirname(__DIR__, 3);
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
    $packageRoot = dirname(__DIR__, 3);
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

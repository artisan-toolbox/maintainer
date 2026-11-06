<?php

it('isolates the APT frontend for each recipe invocation while inheriting unrelated environment', function () {
    $packageRoot = dirname(__DIR__, 3);
    $recipe = $this->directory.'/deploy.php';
    $this->files->put($recipe, <<<'PHP'
<?php
namespace Deployer;
require 'recipe/common.php';
localhost('local');
task('verify:pm2', static function (): void {
    writeln('FRONTEND='.json_encode(getenv('DEBIAN_FRONTEND')));
    writeln('INHERITED='.getenv('MAINTAINER_TEST_INHERITED_ENV'));
});
PHP);
    $originalEnvironment = [
        'DEBIAN_FRONTEND' => getenv('DEBIAN_FRONTEND'),
        'MAINTAINER_TEST_INHERITED_ENV' => getenv('MAINTAINER_TEST_INHERITED_ENV'),
    ];
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;

    try {
        foreach (['noninteractive', 'dialog'] as $frontend) {
            putenv('DEBIAN_FRONTEND='.$frontend);
            putenv('MAINTAINER_TEST_INHERITED_ENV='.$frontend);
            $_ENV['DEBIAN_FRONTEND'] = $_SERVER['DEBIAN_FRONTEND'] = $frontend;
            $_ENV['MAINTAINER_TEST_INHERITED_ENV'] = $_SERVER['MAINTAINER_TEST_INHERITED_ENV'] = $frontend;

            $process = runPm2TaskRecipe($packageRoot, $this->directory, $recipe);

            expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
                ->and($process->getOutput())->toContain('FRONTEND=false', 'INHERITED='.$frontend)
                ->and(getenv('DEBIAN_FRONTEND'))->toBe($frontend)
                ->and(getenv('MAINTAINER_TEST_INHERITED_ENV'))->toBe($frontend);
        }
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;

        foreach ($originalEnvironment as $key => $value) {
            putenv($value === false ? $key : $key.'='.$value);
        }
    }
});

it('runs explicit server maintenance tasks with noninteractive privilege handling', function (string $taskName, array $expectedArguments, bool $apt) {
    $packageRoot = dirname(__DIR__, 3);
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
    $packageRoot = dirname(__DIR__, 3);
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

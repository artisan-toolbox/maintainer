<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/npm.php';

// Config

set('repository', 'git@github.com:wevos-software/DocfyWeb.git');

set('keep_releases', 4);

task('git:set-tag', function () {
    if (empty(input()->getOption('tag'))) {
        $repo = get('repository');
        // Executa comando no shell para listar tags remotas
        exec("git ls-remote --tags $repo", $tags);

        $tags = array_reverse($tags);
        foreach ($tags as $key => $tag) {
            $pos = strrpos($tag, '/'); // posição do último "/"
            $tags[$key] = substr($tag, $pos + 1);
        }
        sort($tags, SORT_NATURAL);
        $tags = array_reverse($tags);
        $tag = askChoice('Choose a git tag', $tags, 0);
        set('branch', $tag);
    }
});

desc('Clear cache');
task('artisan:cache:clear', artisan('cache:clear'));

task('npm:build', function () {
    run('cd {{release_path}} && {{bin/npm}} run build');
});

desc('Stop horizon to it start again');
task('artisan:horizon:terminate', artisan('horizon:terminate'));

desc('Reset pm2 config');
task('pm2:config', function () {
    run('cd {{release_path}} && pm2 delete all && pm2 start pm2.config.cjs && pm2 save');
});

// Hosts
host('129.212.145.127')
    ->setRemoteUser('root')
    ->setDeployPath('/var/www/docfy')
    ->setIdentityFile('./deploy_rsa');

// Hooks

before('deploy', 'git:set-tag');
after('deploy:failed', 'deploy:unlock');
after('deploy:vendors', 'npm:install');
after('npm:install', 'npm:build');
before('deploy:publish', 'artisan:horizon:terminate');
before('deploy:publish', 'artisan:cache:clear');
before('deploy:publish', 'pm2:config');

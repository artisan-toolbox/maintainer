<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/npm.php';
import(getenv('MAINTAINER_CONTRIB'));

/*
|--------------------------------------------------------------------------
| Config
|--------------------------------------------------------------------------
*/

// set('repository', 'git@github.com:foo/bar.git');
// set('keep_releases', 4);
// set('repository_tag_limit', 10);
// set('pm2_config_file', 'pm2.config.cjs');

// add('shared_files', []);
// add('shared_dirs', []);
// add('writable_dirs', []);

/*
|--------------------------------------------------------------------------
| Hosts
|--------------------------------------------------------------------------
*/
host('127.0.0.1')
    ->setRemoteUser('foo_bar')
    ->setDeployPath('/var/www/foo_bar');

/*
|--------------------------------------------------------------------------
| Tasks
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Main deploy task
|--------------------------------------------------------------------------
*/
desc('Deploys your project');
task('deploy', [
    //    'repository:tag',
    'deploy:prepare',
    'deploy:vendors',
    //    'npm:install',
    //    'npm:build',
    'artisan:storage:link',
    'artisan:optimize',
    'artisan:migrate',
    //    'pm2:config',
    'deploy:publish',
    'artisan:reload',
]);

/*
|--------------------------------------------------------------------------
| Hooks
|--------------------------------------------------------------------------
*/

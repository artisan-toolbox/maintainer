<?php

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;

it('exports only the public Maintainer namespace to consumers', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['autoload']['psr-4'])
        ->toBe([
            'ArtisanToolbox\\Maintainer\\' => 'app/',
        ])
        ->and($manifest['autoload']['files'])->toBe([
            'app/Support/client_helpers.php',
        ])
        ->and(class_exists(MaintainerSshKeys::class))->toBeTrue()
        ->and(function_exists('maintainer_rsa_key'))->toBeTrue()
        ->and(function_exists('maintainer_rsa_public_key'))->toBeTrue()
        ->and($manifest['autoload-dev']['psr-4'])
        ->toHaveKeys([
            'App\\Ai\\',
            'App\\Commands\\',
            'App\\Foundation\\',
            'App\\Providers\\',
            'App\\Support\\',
            'Database\\Factories\\',
            'Database\\Seeders\\',
            'Tests\\',
        ])
        ->not->toHaveKey('App\\');
});

it('packages the default Maintainer PHP configuration in the PHAR', function () {
    $boxManifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/box.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($boxManifest['directories'])->toContain('config')
        ->and(dirname(__DIR__, 2).'/config/maintainer.php')->toBeFile()
        ->and(dirname(__DIR__, 2).'/config/maintainer_secrets.php')->toBeFile();
});

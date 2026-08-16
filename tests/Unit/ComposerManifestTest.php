<?php

it('exports only the public Maintainer namespace to consumers', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['autoload']['psr-4'])
        ->toBe([
            'ArtisanToolbox\\Maintainer\\Contracts\\Versionable\\' => 'app/Versionable/Contracts/',
            'ArtisanToolbox\\Maintainer\\Attributes\\Versionable\\' => 'app/Versionable/Attributes/',
        ])
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

it('packages the default Maintainer configuration in the PHAR', function () {
    $boxManifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/box.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($boxManifest['directories'])->toContain('resources')
        ->and(dirname(__DIR__, 2).'/resources/maintainer.json')->toBeFile()
        ->and(dirname(__DIR__, 2).'/resources/maintainer_secrets.json')->toBeFile();
});

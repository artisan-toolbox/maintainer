<?php

it('exports only the public Maintainer namespace to consumers', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['autoload']['psr-4'])
        ->toBe([
            'ArtisanToolbox\\Maintainer\\Versionable\\Contracts\\' => 'app/Versionable/Contracts/',
            'ArtisanToolbox\\Maintainer\\Versionable\\Attributes\\' => 'app/Versionable/Attributes/',
        ])
        ->and($manifest['autoload-dev']['psr-4'])
        ->toHaveKeys([
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

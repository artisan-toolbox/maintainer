<?php

it('identifies itself as Maintainer', function () {
    expect(config('app.name'))->toBe('Maintainer');
});

it('uses maintainer as its source and build executable names', function () {
    $projectRoot = dirname(__DIR__, 2);
    $boxManifest = json_decode(
        file_get_contents($projectRoot.'/box.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $composerManifest = json_decode(
        file_get_contents($projectRoot.'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($projectRoot.'/maintainer')->toBeFile()
        ->and($projectRoot.'/application')->not->toBeFile()
        ->and($boxManifest['main'])->toBe('maintainer')
        ->and($boxManifest['output'])->toBe('maintainer.phar')
        ->and($composerManifest['bin'])->toBe(['builds/maintainer']);
});

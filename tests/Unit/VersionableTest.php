<?php

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;

it('exports the versionable contract to consuming projects', function () {
    $versionable = new class implements Versionable {};

    expect($versionable)->toBeInstanceOf(Versionable::class);
});

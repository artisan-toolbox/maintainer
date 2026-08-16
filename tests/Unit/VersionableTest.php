<?php

use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;

it('exports the versionable contract to consuming projects', function () {
    $versionable = new class implements Versionable {};

    expect($versionable)->toBeInstanceOf(Versionable::class);
});

it('exports the lifecycle and README badge contracts to consuming projects', function () {
    $versionable = new class implements AfterVersioning, BeforeVersioning, Versionable, WithReadmeBadgeVersion
    {
        public static function beforeVersioning(): void {}

        public static function afterVersioning(): void {}
    };

    expect($versionable)
        ->toBeInstanceOf(BeforeVersioning::class)
        ->toBeInstanceOf(AfterVersioning::class)
        ->toBeInstanceOf(WithReadmeBadgeVersion::class);
});

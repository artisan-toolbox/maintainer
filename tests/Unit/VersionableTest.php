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
        public static function beforeVersioning(string $current, string $next): void {}

        public static function afterVersioning(string $current, string $next): void {}
    };

    expect($versionable)
        ->toBeInstanceOf(BeforeVersioning::class)
        ->toBeInstanceOf(AfterVersioning::class)
        ->toBeInstanceOf(WithReadmeBadgeVersion::class);
});

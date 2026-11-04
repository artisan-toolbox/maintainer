<?php

use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use ArtisanToolbox\Maintainer\Versionable\Readme\Head;

it('exports the versionable contract to consuming projects', function () {
    $versionable = new class implements Versionable {};

    expect($versionable)->toBeInstanceOf(Versionable::class);
});

it('exports the lifecycle and README head contracts to consuming projects', function () {
    $versionable = new class implements AfterVersioning, BeforeVersioning, Versionable, WithReadmeHead
    {
        public static function beforeVersioning(string $current, string $next): void {}

        public static function afterVersioning(string $current, string $next): void {}

        public function readmeHead(): Head
        {
            return new Head;
        }
    };

    expect($versionable)
        ->toBeInstanceOf(BeforeVersioning::class)
        ->toBeInstanceOf(AfterVersioning::class)
        ->toBeInstanceOf(WithReadmeHead::class);
});

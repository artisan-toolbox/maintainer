<?php

use App\Support\Quality\Commands\Fix\PintCommand as PintFixCommandImplementation;
use App\Support\Quality\Commands\Fix\RectorCommand as RectorCommandImplementation;
use App\Support\Quality\Commands\Fix\VitePlusCheckCommand as VitePlusCheckFixCommandImplementation;
use App\Support\Quality\Commands\Test\PestCommand as PestCommandImplementation;
use App\Support\Quality\Commands\Test\PhpStanCommand as PhpStanCommandImplementation;
use App\Support\Quality\Commands\Test\PintCommand as PintTestCommandImplementation;
use App\Support\Quality\Commands\Test\VitePlusCheckCommand as VitePlusCheckCommandImplementation;
use App\Support\Quality\Commands\Test\VitePlusTestCommand as VitePlusTestCommandImplementation;
use App\Support\Quality\Commands\Test\VueTscCommand as VueTscCommandImplementation;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsRectorFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheck;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheckFix;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusTest;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVueTscCheck;

it('exports and resolves every configured quality command contract', function () {
    $contracts = [
        RunsPintFix::class => PintFixCommandImplementation::class,
        RunsRectorFix::class => RectorCommandImplementation::class,
        RunsVitePlusCheckFix::class => VitePlusCheckFixCommandImplementation::class,
        RunsPestCheck::class => PestCommandImplementation::class,
        RunsPintCheck::class => PintTestCommandImplementation::class,
        RunsVitePlusCheck::class => VitePlusCheckCommandImplementation::class,
        RunsVitePlusTest::class => VitePlusTestCommandImplementation::class,
        RunsVueTscCheck::class => VueTscCommandImplementation::class,
        RunsPhpStanCheck::class => PhpStanCommandImplementation::class,
    ];

    foreach ($contracts as $contract => $implementation) {
        expect(interface_exists($contract))->toBeTrue()
            ->and(resolve($contract))->toBeInstanceOf($contract)
            ->toBeInstanceOf($implementation);
    }
});

it('declares an explicit Composer export for quality command contracts', function () {
    $manifest = json_decode(
        file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['autoload']['psr-4']['ArtisanToolbox\\Maintainer\\Quality\\Contracts\\'] ?? null)
        ->toBe('app/Quality/Contracts/');
});

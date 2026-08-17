<?php

use App\Support\MaintainerBanner;
use ArtisanToolbox\Maintainer\Maintainer;

it('renders the Maintainer terminal banner', function () {
    $banner = (new MaintainerBanner)->render();

    expect($banner)
        ->toContain('__  __')
        ->toContain('|_|  |_|\\__,_|_|_| |_|\\__\\__,_|_|_| |_|\\___|_| '.Maintainer::VERSION)
        ->toEndWith(Maintainer::VERSION)
        ->and(substr_count($banner, "\n") + 1)
        ->toBe(5);
});

<?php

use App\Support\MaintainerBanner;

it('renders the Maintainer terminal banner', function () {
    $banner = (new MaintainerBanner)->render();

    expect($banner)
        ->toContain('__  __')
        ->toContain('|_|  |_|\\__,_|_|_| |_|\\__\\__,_|_|_| |_|\\___|_|')
        ->and(substr_count($banner, "\n") + 1)
        ->toBe(5);
});

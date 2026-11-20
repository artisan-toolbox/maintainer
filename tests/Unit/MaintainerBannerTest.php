<?php

use App\Support\MaintainerBanner;
use ArtisanToolbox\Maintainer\Maintainer;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;

it('places the running version beside the middle of the wordmark', function () {
    $banner = (new MaintainerBanner)->render();
    $lines = explode(PHP_EOL, $banner);
    $version = 'v'.Maintainer::VERSION;

    expect($lines)->toHaveCount(5)
        ->and($lines[2])->toMatch('/█▀  '.preg_quote($version, '/').'$/')
        ->and(substr_count($banner, $version))->toBe(1)
        ->and($banner)->not->toContain("\033");
});

it('uses a one-line name and version when the full wordmark would wrap', function () {
    $banner = new MaintainerBanner;
    $full = $banner->render();
    $width = max(array_map(mb_strwidth(...), explode(PHP_EOL, $full)));

    expect($banner->render($width))->toBe($full)
        ->and($banner->render($width - 1))->toBe('MAINTAINER  v'.Maintainer::VERSION);
});

it('adds terminal colors without changing the lettering or the version', function (int $width) {
    $banner = new MaintainerBanner;
    $colored = $banner->render($width, decorated: true);

    expect($colored)->toContain("\033[")
        ->and(Helper::removeDecoration(new OutputFormatter, $colored))->toBe($banner->render($width));
})->with([80, 32]);

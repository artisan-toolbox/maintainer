<?php

use App\Support\Release\ReleaseVersionOptions;
use App\Support\Release\SemanticVersion;

it('offers the initial versions when the major has no GitHub release', function () {
    expect((new ReleaseVersionOptions)->forMajor(2, null))->toBe([
        '2.0.0' => 'Stable — 2.0.0',
        '2.0.0-alpha.1' => 'Alpha — 2.0.0-alpha.1',
        '2.0.0-beta.1' => 'Beta — 2.0.0-beta.1',
    ]);
});

it('offers patch and minor transitions after a stable release', function () {
    $latest = (new SemanticVersion)->parse('1.2.3');

    expect((new ReleaseVersionOptions)->forMajor(1, $latest))->toBe([
        '1.2.4' => 'Patch — 1.2.4',
        '1.3.0' => 'Minor — 1.3.0',
        '1.3.0-alpha.1' => 'Minor alpha — 1.3.0-alpha.1',
        '1.3.0-beta.1' => 'Minor beta — 1.3.0-beta.1',
    ]);
});

it('keeps an alpha release in its minor flow', function () {
    $latest = (new SemanticVersion)->parse('1.3.0-alpha.2');

    expect((new ReleaseVersionOptions)->forMajor(1, $latest))->toBe([
        '1.3.0-alpha.3' => 'Next alpha — 1.3.0-alpha.3',
        '1.3.0-beta.1' => 'Beta — 1.3.0-beta.1',
        '1.3.0' => 'Stable — 1.3.0',
    ]);
});

it('keeps a beta release in its minor flow', function () {
    $latest = (new SemanticVersion)->parse('1.3.0-beta.2');

    expect((new ReleaseVersionOptions)->forMajor(1, $latest))->toBe([
        '1.3.0-beta.3' => 'Next beta — 1.3.0-beta.3',
        '1.3.0' => 'Stable — 1.3.0',
    ]);
});

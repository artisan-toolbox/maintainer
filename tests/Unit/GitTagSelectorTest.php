<?php

use ArtisanToolbox\Maintainer\Deployer\GitTagSelector;

it('sorts semantic Git tags from newest stable to prereleases', function () {
    $references = <<<'TAGS'
aaaaaaaa	refs/tags/v1.2.0-alpha
bbbbbbbb	refs/tags/v1.10.0
cccccccc	refs/tags/v1.2.0
dddddddd	refs/tags/v1.2.0-beta
eeeeeeee	refs/tags/v2.0.0
ffffffff	refs/tags/v1.2.0-alpha.2
11111111	refs/tags/v1.2.0-alpha.1
TAGS;

    expect(GitTagSelector::fromRemoteReferences($references, 'main', 10))->toBe([
        'v2.0.0',
        'v1.10.0',
        'v1.2.0',
        'v1.2.0-beta',
        'v1.2.0-alpha.2',
        'v1.2.0-alpha.1',
        'v1.2.0-alpha',
    ]);
});

it('filters tags using the major from a version branch', function () {
    $references = <<<'TAGS'
aaaaaaaa	refs/tags/v1.9.0
bbbbbbbb	refs/tags/2.0.0-alpha
cccccccc	refs/tags/v2.0.0
dddddddd	refs/tags/v3.0.0
TAGS;

    expect(GitTagSelector::fromRemoteReferences($references, '2.x', 10))
        ->toBe(['v2.0.0', '2.0.0-alpha'])
        ->and(GitTagSelector::fromRemoteReferences($references, 'v1.x', 10))
        ->toBe(['v1.9.0']);
});

it('limits the newest Git tags after sorting', function () {
    $references = <<<'TAGS'
aaaaaaaa	refs/tags/v1.0.0
bbbbbbbb	refs/tags/v1.2.0
cccccccc	refs/tags/v1.1.0
TAGS;

    expect(GitTagSelector::fromRemoteReferences($references, 'main', 2))
        ->toBe(['v1.2.0', 'v1.1.0']);
});

it('rejects an invalid Git tag limit', function () {
    GitTagSelector::fromRemoteReferences('', 'main', 0);
})->throws(InvalidArgumentException::class, 'The Git tag limit must be at least 1.');

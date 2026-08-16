<?php

use App\Support\Release\SemanticVersion;

it('accepts supported semantic versions', function (string $version) {
    expect((new SemanticVersion)->isValid($version))->toBeTrue();
})->with([
    'stable' => '1.2.3',
    'zero version' => '0.0.0',
    'alpha' => '1.2.3-alpha',
    'numbered alpha' => '1.2.3-alpha.1',
    'beta' => '1.2.3-beta',
    'numbered beta' => '1.2.3-beta.10',
]);

it('rejects unsupported version formats', function (string $version) {
    expect((new SemanticVersion)->isValid($version))->toBeFalse();
})->with([
    'missing patch' => '1.2',
    'version prefix' => 'v1.2.3',
    'leading zero' => '01.2.3',
    'release candidate' => '1.2.3-rc.1',
    'numeric prerelease' => '1.2.3-1',
    'build metadata' => '1.2.3+build.1',
    'uppercase prerelease' => '1.2.3-ALPHA',
    'prerelease leading zero' => '1.2.3-beta.01',
    'extra prerelease identifier' => '1.2.3-alpha.1.dev',
]);

it('orders prereleases before their stable version', function () {
    $semanticVersion = new SemanticVersion;
    $alpha = $semanticVersion->parse('1.2.3-alpha.2');
    $beta = $semanticVersion->parse('1.2.3-beta.1');
    $stable = $semanticVersion->parse('1.2.3');

    expect($semanticVersion->compare($beta, $alpha))->toBeGreaterThan(0)
        ->and($semanticVersion->compare($stable, $beta))->toBeGreaterThan(0);
});

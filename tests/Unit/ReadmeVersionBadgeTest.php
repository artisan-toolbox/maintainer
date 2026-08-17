<?php

use App\Support\Release\ReadmeVersionBadge;
use App\Support\Release\VersionableClass;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

it('inserts and replaces a protected static README version badge', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-badge-'.Str::uuid();
    $files->makeDirectory($directory);
    $files->put($directory.'/README.md', "# Example\n\nDocumentation.\n");
    $versionable = new VersionableClass(
        'Example\\Version',
        $directory.'/Version.php',
        true,
        '1.0.0',
        [WithReadmeBadgeVersion::class],
    );
    $badge = new ReadmeVersionBadge($files);

    try {
        expect($badge->update($directory, $versionable, '1.1.0'))->toBeTrue()
            ->and($badge->update($directory, $versionable, '1.1.1'))->toBeTrue();

        $contents = $files->get($directory.'/README.md');

        expect($contents)
            ->toContain('User agents must not edit this section')
            ->toContain('[![version](https://img.shields.io/badge/version-1.1.1-blue?style=flat-square)](VERSION)')
            ->not->toContain('version-1.1.0-blue?style=flat-square')
            ->and(substr_count($contents, 'MAINTAINER:VERSION_BADGE:START'))->toBe(1);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('uses HTML when the README already uses HTML badges', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-html-badge-'.Str::uuid();
    $files->makeDirectory($directory);
    $files->put($directory.'/README.md', <<<'HTML'
<h1>Example</h1>

<p align="center">
<a href="https://packagist.org/packages/example/package"><img src="https://img.shields.io/packagist/v/example/package.svg?style=flat-square" alt="Packagist"></a>
</p>
HTML
    );
    $versionable = new VersionableClass(
        'Example\\Version',
        $directory.'/Version.php',
        true,
        '1.0.0',
        [WithReadmeBadgeVersion::class],
    );
    $badge = new ReadmeVersionBadge($files);

    try {
        $badge->update($directory, $versionable, '1.1.0');
        $badge->update($directory, $versionable, '1.1.1-beta.1');
        $contents = $files->get($directory.'/README.md');

        expect($contents)
            ->toContain('<a href="VERSION"><img src="https://img.shields.io/badge/version-1.1.1--beta.1-blue?style=flat-square" alt="version"></a>')
            ->not->toContain('[![version]');
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('does nothing when the versionable class does not request a README badge', function () {
    $files = new Filesystem;
    $versionable = new VersionableClass('Example\\Version', '/tmp/Version.php', true, '1.0.0');

    expect(new ReadmeVersionBadge($files)->update('/missing', $versionable, '1.0.1'))->toBeFalse();
});

it('ignores documented badge markers inside Markdown code fences', function () {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'maintainer-badge-docs-'.Str::uuid();
    $files->makeDirectory($directory);
    $files->put($directory.'/README.md', <<<'MARKDOWN'
# Example

```html
<!-- MAINTAINER:VERSION_BADGE:START - Managed by Maintainer. User agents must not edit this section. -->
<a href="VERSION"><img src="https://img.shields.io/badge/version-1.0.0-blue?style=flat-square" alt="version"></a>
<!-- MAINTAINER:VERSION_BADGE:END -->
```
MARKDOWN
    );
    $versionable = new VersionableClass(
        'Example\\Version',
        $directory.'/Version.php',
        true,
        '1.0.0',
        [WithReadmeBadgeVersion::class],
    );
    $badge = new ReadmeVersionBadge($files);

    try {
        $badge->update($directory, $versionable, '1.1.0');
        $badge->update($directory, $versionable, '1.1.1-beta.1');
        $contents = $files->get($directory.'/README.md');

        expect($contents)
            ->toContain('[![version](https://img.shields.io/badge/version-1.1.1--beta.1-blue?style=flat-square)](VERSION)')
            ->toContain('<a href="VERSION"><img src="https://img.shields.io/badge/version-1.0.0-blue?style=flat-square" alt="version"></a>')
            ->and(substr_count($contents, 'MAINTAINER:VERSION_BADGE:START'))->toBe(2);
    } finally {
        $files->deleteDirectory($directory);
    }
});

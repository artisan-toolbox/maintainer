<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer;

use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;
use ArtisanToolbox\Maintainer\Versionable\Readme\Head;
use Illuminate\Support\Facades\Process;

final class Maintainer implements BeforeVersioning, Versionable, WithReadmeHead
{
    public const string VERSION = '1.9.0';

    public static function beforeVersioning(string $current, string $next): void
    {
        Process::path(base_path())
            ->forever()
            ->run([PHP_BINARY, base_path('maintainer'), 'app:build'])
            ->throw();
    }

    public function readmeHead(): Head
    {
        return (new Head)
            ->withLogo(src: 'logo/maintainer-icon.svg', alt: '', width: 88)
            ->withTitle('Maintainer')
            ->withDescription('Automated validation, quality assurance, versioning, and release workflows for Laravel packages and applications.')
            ->addBadge(Badge::releaseVersion('https://github.com/artisan-toolbox/maintainer/releases'))
            ->addBadge(Badge::make(
                link: 'https://packagist.org/packages/artisan-toolbox/maintainer',
                imgSrc: 'https://img.shields.io/packagist/v/artisan-toolbox/maintainer.svg?style=flat-square',
                imgAlt: 'Packagist',
            ))
            ->addBadge(Badge::make(
                link: 'https://github.com/artisan-toolbox/maintainer/actions',
                imgSrc: 'https://img.shields.io/github/actions/workflow/status/artisan-toolbox/maintainer/tests.yml?branch=1.x&label=Tests&style=flat-square',
                imgAlt: 'Tests',
            ));
    }
}

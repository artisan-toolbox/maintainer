<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer;

use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;
use Illuminate\Support\Facades\Process;

final class Maintainer implements BeforeVersioning, Versionable, WithReadmeBadgeVersion
{
    public const string VERSION = '1.1.0';

    public static function beforeVersioning(string $current, string $next): void
    {
        Process::path(base_path())
            ->forever()
            ->run([PHP_BINARY, base_path('maintainer'), 'app:build'])
            ->throw();
    }
}

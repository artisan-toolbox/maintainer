<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer;

use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;
use Illuminate\Support\Facades\Process;

final class Maintainer implements BeforeVersioning, Versionable, WithReadmeBadgeVersion
{
    public static function beforeVersioning(string $current, string $next): void
    {
        $projectRoot = dirname(__DIR__);

        Process::path($projectRoot)
            ->forever()
            ->run([PHP_BINARY, $projectRoot.DIRECTORY_SEPARATOR.'maintainer', 'app:build'])
            ->throw();
    }
}

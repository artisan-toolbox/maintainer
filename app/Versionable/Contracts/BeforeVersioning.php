<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Contracts;

/**
 * Runs project-specific preparation after version selection and before release files change.
 */
interface BeforeVersioning
{
    public static function beforeVersioning(string $current, string $next): void;
}

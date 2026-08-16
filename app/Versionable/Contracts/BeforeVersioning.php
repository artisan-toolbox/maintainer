<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Contracts;

/**
 * Runs project-specific preparation immediately before version selection begins.
 */
interface BeforeVersioning
{
    public static function beforeVersioning(): void;
}

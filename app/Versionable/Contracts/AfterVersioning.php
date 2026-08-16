<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Contracts;

/**
 * Runs project-specific follow-up after the release is pushed and published.
 */
interface AfterVersioning
{
    public static function afterVersioning(): void;
}

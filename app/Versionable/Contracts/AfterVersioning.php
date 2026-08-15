<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Contracts\Versionable;

interface AfterVersioning
{
    public static function afterVersioning(): void;
}

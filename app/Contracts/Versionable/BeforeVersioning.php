<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Contracts\Versionable;

interface BeforeVersioning
{
    public static function beforeVersioning(): void;
}

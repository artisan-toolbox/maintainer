<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Contracts;

interface BeforeVersioning
{
    public static function beforeVersioning(): void;
}

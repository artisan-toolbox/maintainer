<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Versionable\Contracts;

use ArtisanToolbox\Maintainer\Versionable\Readme\Head;

interface WithReadmeHead
{
    public function readmeHead(): Head;
}

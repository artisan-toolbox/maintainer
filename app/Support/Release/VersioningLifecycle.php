<?php

namespace App\Support\Release;

use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use RuntimeException;

final class VersioningLifecycle
{
    public function before(VersionableClass $versionable, string $current, string $next): bool
    {
        if (! $versionable->implements(BeforeVersioning::class)) {
            return false;
        }

        $this->load($versionable);
        $class = $versionable->name;
        $class::beforeVersioning($current, $next);

        return true;
    }

    public function after(VersionableClass $versionable, string $current, string $next): bool
    {
        if (! $versionable->implements(AfterVersioning::class)) {
            return false;
        }

        $this->load($versionable);
        $class = $versionable->name;
        $class::afterVersioning($current, $next);

        return true;
    }

    private function load(VersionableClass $versionable): void
    {
        if (! class_exists($versionable->name, false)) {
            require_once $versionable->file;
        }

        throw_unless(class_exists($versionable->name, false), RuntimeException::class, "Unable to load {$versionable->name}.");
    }
}

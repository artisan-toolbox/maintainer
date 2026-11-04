<?php

namespace App\Support\Release;

use ArtisanToolbox\Maintainer\Versionable\Contracts\AfterVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\BeforeVersioning;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use ArtisanToolbox\Maintainer\Versionable\Readme\Head;
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

    public function readmeHead(VersionableClass $versionable): ?Head
    {
        if (! $versionable->implements(WithReadmeHead::class)) {
            return null;
        }

        $this->load($versionable);
        $class = $versionable->name;

        throw_unless(is_a($class, WithReadmeHead::class, true), RuntimeException::class, "The loaded {$class} does not implement WithReadmeHead.");

        return (new $class)->readmeHead();
    }

    private function load(VersionableClass $versionable): void
    {
        if (! class_exists($versionable->name, false)) {
            require_once $versionable->file;
        }

        throw_unless(class_exists($versionable->name, false), RuntimeException::class, "Unable to load {$versionable->name}.");
    }
}

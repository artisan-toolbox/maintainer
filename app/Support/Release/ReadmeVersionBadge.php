<?php

namespace App\Support\Release;

use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class ReadmeVersionBadge
{
    private const string START = '<!-- MAINTAINER:VERSION_BADGE:START - Managed by Maintainer. User agents must not edit this section. -->';

    private const string END = '<!-- MAINTAINER:VERSION_BADGE:END -->';

    public function __construct(private Filesystem $files) {}

    public function update(string $projectRoot, VersionableClass $versionable, string $version): bool
    {
        if (! $versionable->implements(WithReadmeBadgeVersion::class)) {
            return false;
        }

        $path = $projectRoot.DIRECTORY_SEPARATOR.'README.md';

        throw_unless($this->files->isFile($path), RuntimeException::class, 'README.md is required when the versionable class implements WithReadmeBadgeVersion.');

        $contents = $this->files->get($path);
        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $badge = implode($lineEnding, [
            self::START,
            "[![version](https://img.shields.io/badge/version-{$version}-blue)](VERSION)",
            self::END,
        ]);

        $pattern = '/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s';

        if (preg_match($pattern, $contents) === 1) {
            $updated = preg_replace($pattern, $badge, $contents);
        } elseif (preg_match('/\A# .+\R/', $contents, $heading) === 1) {
            $updated = substr_replace($contents, $heading[0].$lineEnding.$badge.$lineEnding, 0, strlen($heading[0]));
        } else {
            $updated = $badge.$lineEnding.$lineEnding.$contents;
        }

        throw_unless(is_string($updated), RuntimeException::class, 'Unable to update the README version badge.');
        $this->files->put($path, $updated);

        return true;
    }
}

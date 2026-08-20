<?php

namespace App\Support\Release;

use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeBadgeVersion;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

use function Illuminate\Filesystem\join_paths;

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

        $path = join_paths($projectRoot, 'README.md');

        throw_unless($this->files->isFile($path), RuntimeException::class, 'README.md is required when the versionable class implements WithReadmeBadgeVersion.');

        $contents = $this->files->get($path);
        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $badgeVersion = str_replace('-', '--', $version);
        $managedBlock = $this->managedBlock($contents);
        $badgeMarkup = $this->usesHtmlBadge($contents, $managedBlock)
            ? "<a href=\"VERSION\"><img src=\"https://img.shields.io/badge/version-{$badgeVersion}-blue?style=flat-square\" alt=\"version\"></a>"
            : "[![version](https://img.shields.io/badge/version-{$badgeVersion}-blue?style=flat-square)](VERSION)";
        $badge = implode($lineEnding, [
            self::START,
            $badgeMarkup,
            self::END,
        ]);

        if ($managedBlock !== null) {
            $updated = substr_replace($contents, $badge, $managedBlock['offset'], $managedBlock['length']);
        } elseif (preg_match('/\A# .+\R/', $contents, $heading) === 1) {
            $updated = substr_replace($contents, $heading[0].$lineEnding.$badge.$lineEnding, 0, strlen($heading[0]));
        } else {
            $updated = $badge.$lineEnding.$lineEnding.$contents;
        }

        $this->files->put($path, $updated);

        return true;
    }

    /**
     * @return array{offset: int, length: int}|null
     */
    private function managedBlock(string $contents): ?array
    {
        $pattern = '/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s';
        preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$block, $offset]) {
            if (! $this->insideMarkdownFence($contents, $offset)) {
                return ['offset' => $offset, 'length' => strlen($block)];
            }
        }

        return null;
    }

    /**
     * @param  array{offset: int, length: int}|null  $managedBlock
     */
    private function usesHtmlBadge(string $contents, ?array $managedBlock): bool
    {
        if ($managedBlock !== null) {
            $block = substr($contents, $managedBlock['offset'], $managedBlock['length']);

            return preg_match('/<img\b/i', $block) === 1;
        }

        $patterns = [
            'html' => '/<img\b[^>]*\bsrc=["\'][^"\']*img\.shields\.io[^"\']*["\'][^>]*>/i',
            'markdown' => '/!\[[^\]]*\]\(https?:\/\/img\.shields\.io\/[^)]+\)/i',
        ];
        $badges = [];

        foreach ($patterns as $format => $pattern) {
            preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [$badge, $offset]) {
                if (! $this->insideMarkdownFence($contents, $offset)) {
                    $badges[] = ['format' => $format, 'offset' => $offset];
                }
            }
        }

        usort($badges, fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        return ($badges[0]['format'] ?? 'markdown') === 'html';
    }

    private function insideMarkdownFence(string $contents, int $offset): bool
    {
        $prefix = substr($contents, 0, $offset);
        preg_match_all('/^(?: {0,3})(`{3,}|~{3,})[^\r\n]*$/m', $prefix, $matches);
        $openFence = null;

        foreach ($matches[1] as $delimiter) {
            if ($openFence === null) {
                $openFence = $delimiter;

                continue;
            }

            if ($delimiter[0] === $openFence[0] && strlen($delimiter) >= strlen($openFence)) {
                $openFence = null;
            }
        }

        return $openFence !== null;
    }
}

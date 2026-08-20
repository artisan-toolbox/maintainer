<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Deployer;

use InvalidArgumentException;

/** @internal */
final class GitTagSelector
{
    /**
     * Build a newest-first tag list from `git ls-remote --tags --refs` output.
     *
     * @return list<string>
     */
    public static function fromRemoteReferences(string $references, string $branch, int $limit): array
    {
        throw_if($limit < 1, InvalidArgumentException::class, 'The Git tag limit must be at least 1.');

        $tags = [];

        foreach (preg_split('/\R/', trim($references)) ?: [] as $reference) {
            if (preg_match('/\srefs\/tags\/(.+)$/', $reference, $matches) === 1) {
                $tags[] = $matches[1];
            }
        }

        $tags = array_values(array_unique($tags));
        $major = self::majorFromBranch($branch);

        if ($major !== null) {
            $tags = array_values(array_filter(
                $tags,
                static fn (string $tag): bool => preg_match('/^[vV]?'.preg_quote((string) $major, '/').'(?:\.|$)/', $tag) === 1,
            ));
        }

        usort($tags, static function (string $left, string $right): int {
            $comparison = version_compare(self::normalizedVersion($right), self::normalizedVersion($left));

            return $comparison !== 0 ? $comparison : strnatcasecmp($right, $left);
        });

        return array_slice($tags, 0, $limit);
    }

    public static function majorFromBranch(string $branch): ?int
    {
        if (preg_match('/^[vV]?(\d+)\.x$/', trim($branch), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function normalizedVersion(string $tag): string
    {
        return preg_replace('/^[vV](?=\d)/', '', $tag) ?? $tag;
    }
}

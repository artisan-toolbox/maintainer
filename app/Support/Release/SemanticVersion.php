<?php

namespace App\Support\Release;

final class SemanticVersion
{
    private const string PATTERN = '/^(?<major>0|[1-9]\d*)\.(?<minor>0|[1-9]\d*)\.(?<patch>0|[1-9]\d*)(?:-(?<prerelease>alpha|beta)(?:\.(?<prerelease_number>0|[1-9]\d*))?)?\z/';

    public function isValid(string $version): bool
    {
        return $this->parse($version) !== null;
    }

    public function parse(string $version): ?SemanticVersionNumber
    {
        if (preg_match(self::PATTERN, $version, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        return new SemanticVersionNumber(
            (int) $matches['major'],
            (int) $matches['minor'],
            (int) $matches['patch'],
            is_string($matches['prerelease']) ? $matches['prerelease'] : null,
            is_string($matches['prerelease_number'])
                ? (int) $matches['prerelease_number']
                : null,
        );
    }

    public function compare(SemanticVersionNumber $left, SemanticVersionNumber $right): int
    {
        foreach (['major', 'minor', 'patch'] as $part) {
            $comparison = $left->{$part} <=> $right->{$part};

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        if ($left->prerelease === null || $right->prerelease === null) {
            return ($left->prerelease === null) <=> ($right->prerelease === null);
        }

        $stageComparison = $this->stageWeight($left->prerelease) <=> $this->stageWeight($right->prerelease);

        return $stageComparison !== 0
            ? $stageComparison
            : ($left->prereleaseNumber ?? -1) <=> ($right->prereleaseNumber ?? -1);
    }

    private function stageWeight(string $stage): int
    {
        return $stage === 'alpha' ? 0 : 1;
    }
}

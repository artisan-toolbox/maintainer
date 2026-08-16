<?php

namespace App\Support\Release;

final class ReleaseVersionOptions
{
    /**
     * @return array<string, string>
     */
    public function forMajor(int $major, ?SemanticVersionNumber $latest): array
    {
        if ($latest === null) {
            return [
                "{$major}.0.0" => "Stable — {$major}.0.0",
                "{$major}.0.0-alpha.1" => "Alpha — {$major}.0.0-alpha.1",
                "{$major}.0.0-beta.1" => "Beta — {$major}.0.0-beta.1",
            ];
        }

        if ($latest->prerelease === 'alpha') {
            return $this->alphaOptions($latest);
        }

        if ($latest->prerelease === 'beta') {
            return $this->betaOptions($latest);
        }

        $nextPatch = "{$major}.{$latest->minor}.".($latest->patch + 1);
        $nextMinor = "{$major}.".($latest->minor + 1).'.0';

        return [
            $nextPatch => "Patch — {$nextPatch}",
            $nextMinor => "Minor — {$nextMinor}",
            $nextMinor.'-alpha.1' => "Minor alpha — {$nextMinor}-alpha.1",
            $nextMinor.'-beta.1' => "Minor beta — {$nextMinor}-beta.1",
        ];
    }

    /**
     * @return array<string, string>
     */
    private function alphaOptions(SemanticVersionNumber $latest): array
    {
        $base = "{$latest->major}.{$latest->minor}.{$latest->patch}";
        $nextAlpha = $base.'-alpha.'.(($latest->prereleaseNumber ?? 0) + 1);

        return [
            $nextAlpha => "Next alpha — {$nextAlpha}",
            $base.'-beta.1' => "Beta — {$base}-beta.1",
            $base => "Stable — {$base}",
        ];
    }

    /**
     * @return array<string, string>
     */
    private function betaOptions(SemanticVersionNumber $latest): array
    {
        $base = "{$latest->major}.{$latest->minor}.{$latest->patch}";
        $nextBeta = $base.'-beta.'.(($latest->prereleaseNumber ?? 0) + 1);

        return [
            $nextBeta => "Next beta — {$nextBeta}",
            $base => "Stable — {$base}",
        ];
    }
}

<?php

namespace App\Support\Quality\Commands\Fix;

use App\Support\Quality\PackageScriptQualityCommand;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusCheckFix;

final class VitePlusCheckCommand extends PackageScriptQualityCommand implements RunsVitePlusCheckFix
{
    public function name(): string
    {
        return 'vite-plus-check';
    }

    public function label(): string
    {
        return 'Vite+ Check';
    }

    protected function expectedCommand(): string
    {
        return '`vp check --fix`';
    }

    protected function matches(string $script): bool
    {
        return $this->hasMatchingSegment($script, '/^vp\s+check\b(?=.*--fix\b).*$/');
    }

    protected function binaryFilename(): string
    {
        return 'vp';
    }
}

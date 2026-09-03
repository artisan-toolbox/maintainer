<?php

namespace App\Support\Quality\Commands\Test;

use App\Support\Quality\PackageScriptQualityCommand;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVitePlusTest;

final class VitePlusTestCommand extends PackageScriptQualityCommand implements RunsVitePlusTest
{
    public function name(): string
    {
        return 'vite-plus-test';
    }

    public function label(): string
    {
        return 'Vite+ Test';
    }

    protected function expectedCommand(): string
    {
        return '`vp test`';
    }

    protected function matches(string $script): bool
    {
        return $this->hasMatchingSegment($script, '/^vp\s+test\b.*$/');
    }

    protected function binaryFilename(): string
    {
        return 'vp';
    }
}

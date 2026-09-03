<?php

namespace App\Support\Quality\Commands\Test;

use App\Support\Quality\PackageScriptQualityCommand;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsVueTscCheck;

final class VueTscCommand extends PackageScriptQualityCommand implements RunsVueTscCheck
{
    public function name(): string
    {
        return 'vue-tsc';
    }

    public function label(): string
    {
        return 'vue-tsc';
    }

    protected function expectedCommand(): string
    {
        return '`vue-tsc --noEmit`';
    }

    protected function matches(string $script): bool
    {
        return $this->hasMatchingSegment($script, '/^vue-tsc\b(?=.*--noEmit\b).*$/');
    }

    protected function binaryFilename(): string
    {
        return 'vue-tsc';
    }
}

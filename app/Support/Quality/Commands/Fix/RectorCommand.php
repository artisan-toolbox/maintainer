<?php

namespace App\Support\Quality\Commands\Fix;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Quality\ComposerQualityCommand;
use App\Support\Quality\QualityTool;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsRectorFix;

final class RectorCommand extends ComposerQualityCommand implements RunsRectorFix
{
    public function name(): string
    {
        return 'rector';
    }

    public function label(): string
    {
        return 'Rector';
    }

    public function configurationTool(): QualityTool
    {
        return QualityTool::Rector;
    }

    public function command(string $projectRoot, ?string $configurationPath, MaintainerConfiguration $configuration): array
    {
        return $this->binaryCommand($projectRoot, ['process', '--config', (string) $configurationPath]);
    }

    protected function binaryFilename(): string
    {
        return 'rector';
    }
}

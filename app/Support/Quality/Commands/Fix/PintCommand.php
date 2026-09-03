<?php

namespace App\Support\Quality\Commands\Fix;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Quality\ComposerQualityCommand;
use App\Support\Quality\QualityTool;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintFix;

final class PintCommand extends ComposerQualityCommand implements RunsPintFix
{
    public function name(): string
    {
        return 'pint';
    }

    public function label(): string
    {
        return 'Pint';
    }

    public function configurationTool(): QualityTool
    {
        return QualityTool::Pint;
    }

    public function command(string $projectRoot, ?string $configurationPath, MaintainerConfiguration $configuration): array
    {
        return $this->binaryCommand($projectRoot, ['--config', (string) $configurationPath]);
    }

    protected function binaryFilename(): string
    {
        return 'pint';
    }
}

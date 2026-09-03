<?php

namespace App\Support\Quality\Commands\Test;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Quality\ComposerQualityCommand;
use App\Support\Quality\QualityTool;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPintCheck;

final class PintCommand extends ComposerQualityCommand implements RunsPintCheck
{
    public function name(): string
    {
        return 'pint';
    }

    public function label(): string
    {
        return 'Pint test';
    }

    public function configurationTool(): QualityTool
    {
        return QualityTool::Pint;
    }

    public function command(string $projectRoot, ?string $configurationPath, MaintainerConfiguration $configuration): array
    {
        return $this->binaryCommand($projectRoot, ['--test', '--config', (string) $configurationPath]);
    }

    protected function binaryFilename(): string
    {
        return 'pint';
    }
}

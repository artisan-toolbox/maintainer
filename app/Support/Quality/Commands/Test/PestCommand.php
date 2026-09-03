<?php

namespace App\Support\Quality\Commands\Test;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Quality\ComposerQualityCommand;
use App\Support\Quality\QualityTool;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPestCheck;
use RuntimeException;

final class PestCommand extends ComposerQualityCommand implements RunsPestCheck
{
    public function name(): string
    {
        return 'pest';
    }

    public function label(): string
    {
        return 'Pest';
    }

    public function configurationTool(): QualityTool
    {
        return QualityTool::Pest;
    }

    public function command(string $projectRoot, ?string $configurationPath, MaintainerConfiguration $configuration): array
    {
        $parallel = $configuration->get('quality.pest.parallel');

        throw_unless(is_bool($parallel), RuntimeException::class, 'quality.pest.parallel must be true or false.');

        return $this->binaryCommand($projectRoot, [
            '--configuration',
            (string) $configurationPath,
            ...($parallel ? ['--parallel'] : []),
        ]);
    }

    protected function binaryFilename(): string
    {
        return 'pest';
    }
}

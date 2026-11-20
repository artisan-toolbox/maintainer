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
        $processes = $configuration->get('quality.pest.processes');

        throw_unless(is_bool($parallel), RuntimeException::class, 'quality.pest.parallel must be true or false.');
        throw_unless(
            $processes === null || (is_int($processes) && $processes > 0),
            RuntimeException::class,
            'quality.pest.processes must be a positive integer or null.',
        );

        $arguments = ['--configuration', (string) $configurationPath];

        if ($parallel) {
            $arguments[] = '--parallel';

            if ($processes !== null) {
                $arguments[] = "--processes={$processes}";
            }
        }

        return $this->binaryCommand($projectRoot, $arguments);
    }

    protected function binaryFilename(): string
    {
        return 'pest';
    }
}

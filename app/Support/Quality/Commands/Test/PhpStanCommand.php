<?php

namespace App\Support\Quality\Commands\Test;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Quality\ComposerQualityCommand;
use App\Support\Quality\QualityTool;
use ArtisanToolbox\Maintainer\Quality\Contracts\RunsPhpStanCheck;
use RuntimeException;

final class PhpStanCommand extends ComposerQualityCommand implements RunsPhpStanCheck
{
    public function name(): string
    {
        return 'phpstan';
    }

    public function label(): string
    {
        return 'PHPStan';
    }

    public function configurationTool(): QualityTool
    {
        return QualityTool::PhpStan;
    }

    public function command(string $projectRoot, ?string $configurationPath, MaintainerConfiguration $configuration): array
    {
        $memoryLimit = $configuration->get('quality.phpstan.memory_limit');

        throw_unless(
            is_string($memoryLimit) && preg_match('/^(?:-1|[1-9]\d*[KMG]?)$/i', $memoryLimit) === 1,
            RuntimeException::class,
            'quality.phpstan.memory_limit must be -1, a byte count, or a value such as 512M or 2G.',
        );

        return $this->binaryCommand($projectRoot, [
            'analyse',
            '--configuration',
            (string) $configurationPath,
            "--memory-limit={$memoryLimit}",
        ]);
    }

    protected function binaryFilename(): string
    {
        return 'phpstan';
    }
}

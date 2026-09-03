<?php

namespace App\Support\Quality;

use App\Support\Configuration\MaintainerConfiguration;

interface QualityCommand
{
    public function name(): string;

    public function label(): string;

    public function availability(string $projectRoot): QualityCommandAvailability;

    public function configurationTool(): ?QualityTool;

    /**
     * @return list<string>
     */
    public function command(
        string $projectRoot,
        ?string $configurationPath,
        MaintainerConfiguration $configuration,
    ): array;
}

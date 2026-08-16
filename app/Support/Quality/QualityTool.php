<?php

namespace App\Support\Quality;

enum QualityTool: string
{
    case Pint = 'pint';
    case Rector = 'rector';
    case PhpStan = 'phpstan';
    case Pest = 'pest';

    public function label(): string
    {
        return match ($this) {
            self::Pint => 'Pint',
            self::Rector => 'Rector',
            self::PhpStan => 'PHPStan',
            self::Pest => 'Pest',
        };
    }

    /**
     * @return list<string>
     */
    public function configurationFilenames(): array
    {
        return match ($this) {
            self::Pint => ['pint.json'],
            self::Rector => ['rector.php'],
            self::PhpStan => ['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'],
            self::Pest => ['phpunit.xml', 'phpunit.xml.dist'],
        };
    }

    public function defaultConfigurationFilename(): string
    {
        return $this->configurationFilenames()[0];
    }

    public function binaryFilename(): string
    {
        return $this->value;
    }

    /**
     * Build the command with the consuming project's configuration explicitly selected.
     *
     * @param  list<string>  $additionalArguments
     * @return list<string>
     */
    public function command(
        string $binary,
        string $configurationPath,
        array $additionalArguments = [],
    ): array {
        return [...match ($this) {
            self::Pint => [$binary, '--config', $configurationPath],
            self::Rector => [$binary, 'process', '--config', $configurationPath],
            self::PhpStan => [$binary, 'analyse', '--configuration', $configurationPath],
            self::Pest => [$binary, '--configuration', $configurationPath],
        }, ...$additionalArguments];
    }
}

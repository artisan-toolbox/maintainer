<?php

namespace App\Support\Configuration;

use App\Support\Quality\LaravelProjectType;

enum PublishableConfiguration: string
{
    case Maintainer = 'maintainer';
    case MaintainerSecrets = 'maintainer-secrets';
    case Pint = 'pint';
    case Rector = 'rector';
    case PhpStan = 'phpstan';
    case Pest = 'pest';
    case Deployer = 'deployer';

    public function label(): string
    {
        return match ($this) {
            self::Maintainer => 'Maintainer settings',
            self::MaintainerSecrets => 'Maintainer secrets',
            self::Pint => 'Pint (pint.json)',
            self::Rector => 'Rector (rector.php)',
            self::PhpStan => 'PHPStan (phpstan.neon)',
            self::Pest => 'Pest / PHPUnit (phpunit.xml)',
            self::Deployer => 'Deployer (deploy.php)',
        };
    }

    public function configurationLabel(): string
    {
        return match ($this) {
            self::Maintainer => 'Maintainer',
            self::MaintainerSecrets => 'Maintainer secrets',
            self::Pint => 'Pint',
            self::Rector => 'Rector',
            self::PhpStan => 'PHPStan',
            self::Pest => 'Pest',
            self::Deployer => 'Deployer',
        };
    }

    public function filename(): string
    {
        return match ($this) {
            self::Maintainer => 'maintainer.php',
            self::MaintainerSecrets => 'maintainer_secrets.php',
            self::Pint => 'pint.json',
            self::Rector => 'rector.php',
            self::PhpStan => 'phpstan.neon',
            self::Pest => 'phpunit.xml',
            self::Deployer => 'deploy.php',
        };
    }

    public function hasProjectSpecificTemplate(): bool
    {
        return match ($this) {
            self::Rector, self::PhpStan, self::Pest => true,
            self::Maintainer, self::MaintainerSecrets, self::Pint, self::Deployer => false,
        };
    }

    public function isMaintainerConfiguration(): bool
    {
        return $this === self::Maintainer || $this === self::MaintainerSecrets;
    }

    public function userConfigurationName(): ?string
    {
        return match ($this) {
            self::Maintainer => 'maintainer',
            self::MaintainerSecrets => 'maintainer_secrets',
            default => null,
        };
    }

    public function templatePath(string $templateRoot, LaravelProjectType $projectType): string
    {
        $directory = $this->hasProjectSpecificTemplate()
            && $projectType === LaravelProjectType::Package
                ? 'laravel-package'.DIRECTORY_SEPARATOR
                : '';

        return $templateRoot.DIRECTORY_SEPARATOR.$directory.$this->filename();
    }
}

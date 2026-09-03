<?php

namespace App\Support\Quality;

enum QualityTool: string
{
    case Pint = 'pint';
    case Rector = 'rector';
    case PhpStan = 'phpstan';
    case Pest = 'pest';

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
}

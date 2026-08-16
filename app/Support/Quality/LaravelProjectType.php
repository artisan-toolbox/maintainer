<?php

namespace App\Support\Quality;

enum LaravelProjectType: string
{
    case Application = 'laravel-application';
    case Package = 'laravel-package';

    public function label(): string
    {
        return match ($this) {
            self::Application => 'Laravel application',
            self::Package => 'Laravel package',
        };
    }
}

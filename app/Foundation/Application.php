<?php

namespace App\Foundation;

use LaravelZero\Framework\Application as LaravelZeroApplication;

final class Application extends LaravelZeroApplication
{
    /**
     * Return the internal namespace without exposing it through the package autoloader.
     */
    public function getNamespace(): string
    {
        return 'App\\';
    }
}

<?php

namespace App\Foundation;

use LaravelZero\Framework\Application as LaravelZeroApplication;

final class Application extends LaravelZeroApplication
{
    public function joinPaths($basePath, $path = ''): string
    {
        $joined = parent::joinPaths($basePath, $path);

        return str_starts_with($joined, 'phar://') ? str_replace('\\', '/', $joined) : $joined;
    }

    /**
     * Return the internal namespace without exposing it through the package autoloader.
     */
    public function getNamespace(): string
    {
        return 'App\\';
    }
}

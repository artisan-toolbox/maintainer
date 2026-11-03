<?php

namespace App\Foundation;

use LaravelZero\Framework\Application as LaravelZeroApplication;

final class Application extends LaravelZeroApplication
{
    public function joinPaths($basePath, $path = ''): string
    {
        return self::normalizeArchivePath(parent::joinPaths($basePath, $path));
    }

    public static function normalizeArchivePath(string $path): string
    {
        return str_starts_with($path, 'phar://') ? str_replace('\\', '/', $path) : $path;
    }

    /**
     * Return the internal namespace without exposing it through the package autoloader.
     */
    public function getNamespace(): string
    {
        return 'App\\';
    }
}

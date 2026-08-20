<?php

namespace App\Providers;

use App\Support\Configuration\MaintainerSecrets;
use ArtisanToolbox\Maintainer\Encryption\MaintainerEncrypterFactory;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\ServiceProvider;

final class EncryptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('encrypter', function (): Encrypter {
            return MaintainerEncrypterFactory::make(
                $this->app->make(MaintainerSecrets::class)->key(),
            );
        });

        $this->app->alias('encrypter', EncrypterContract::class);
        $this->app->alias('encrypter', StringEncrypter::class);
    }
}

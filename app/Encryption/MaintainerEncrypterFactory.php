<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Encryption;

use Illuminate\Encryption\Encrypter;
use Illuminate\Encryption\MissingAppKeyException;
use RuntimeException;

final class MaintainerEncrypterFactory
{
    public static function make(mixed $key): Encrypter
    {
        throw_if(! is_string($key) || $key === '', MissingAppKeyException::class, 'No Maintainer encryption key has been specified. Configure maintainer_secrets.key or APP_KEY.');

        if (str_starts_with($key, 'base64:')) {
            $decodedKey = base64_decode(substr($key, 7), true);

            throw_unless(is_string($decodedKey), RuntimeException::class, 'The configured Maintainer encryption key is not valid base64.');

            $key = $decodedKey;
        }

        return new Encrypter($key, 'AES-256-CBC');
    }
}

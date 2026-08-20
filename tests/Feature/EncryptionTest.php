<?php

use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;

it('encrypts and decrypts values with APP_KEY from the consuming project environment', function () {
    $variable = 'APP_KEY';
    forgetTestEnvironmentVariable($variable);

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $files->put($directory.'/.env', "APP_KEY={$key}\n");

            $encrypted = Crypt::encryptString('maintainer secret');

            expect($encrypted)->not->toContain('maintainer secret')
                ->and(Crypt::decryptString($encrypted))->toBe('maintainer secret')
                ->and(decrypt(encrypt(['protected' => true])))->toBe(['protected' => true])
                ->and(resolve(EncrypterContract::class))->toBe(resolve('encrypter'));
        });
    } finally {
        forgetTestEnvironmentVariable($variable);
    }
});

it('uses an explicit key from the Maintainer secrets configuration', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
            'key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        $encrypted = Crypt::encryptString('configured secret');

        expect(Crypt::decryptString($encrypted))->toBe('configured secret');
    });
});

it('throws the Laravel missing key exception only when encryption is used', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
            'key' => null,
        ]);

        expect(fn () => Crypt::encryptString('secret'))
            ->toThrow(
                MissingAppKeyException::class,
                'No Maintainer encryption key has been specified. Configure maintainer_secrets.key or APP_KEY.',
            );
    });
});

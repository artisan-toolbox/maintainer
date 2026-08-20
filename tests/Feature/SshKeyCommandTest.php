<?php

use App\Support\Ssh\Ed25519KeyGenerator;
use ArtisanToolbox\Maintainer\Encryption\MaintainerEncrypterFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;

it('prints the stored private key and derives its public key on demand', function () {
    forgetTestEnvironmentVariable('APP_KEY');

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
            $privateKey = resolve(Ed25519KeyGenerator::class)->generatePrivateKey('owner@example.com');

            putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
                'ssh_key' => Crypt::encryptString($privateKey),
            ]);

            $this->artisan('ssh:key')
                ->expectsOutputToContain('-----BEGIN OPENSSH PRIVATE KEY-----')
                ->assertSuccessful();

            $this->artisan('ssh:public')
                ->expectsOutputToContain('ssh-ed25519 ')
                ->assertSuccessful();

            expect(require $directory.'/config/dev_maintainer_secrets.php')
                ->not->toHaveKey('rsa_public_key');
        });
    } finally {
        forgetTestEnvironmentVariable('APP_KEY');
    }
});

it('fails when the secrets file has no generated SSH key', function () {
    forgetTestEnvironmentVariable('APP_KEY');

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
            putPhpConfiguration($files, $directory.'/config/dev_maintainer_secrets.php', [
                'ssh_key' => null,
            ]);

            $this->artisan('ssh:key')
                ->expectsOutputToContain('Maintainer secrets do not contain an encrypted ssh_key')
                ->assertFailed();
        });
    } finally {
        forgetTestEnvironmentVariable('APP_KEY');
    }
});

it('exposes the same key service directly through the consumer helpers', function () {
    forgetTestEnvironmentVariable('APP_KEY');

    try {
        withinTemporaryProject(function (string $directory, Filesystem $files) {
            $files->put($directory.'/.env', 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n");
            $maintainerKey = 'base64:'.base64_encode(random_bytes(32));
            $privateKey = resolve(Ed25519KeyGenerator::class)->generatePrivateKey('owner@example.com');

            config()->set('maintainer_secrets.key', $maintainerKey);
            config()->set('maintainer_secrets.ssh_key', MaintainerEncrypterFactory::make($maintainerKey)->encryptString($privateKey));

            expect(maintainer_ssh_key())->toBe($privateKey)
                ->and(maintainer_ssh_public_key())
                ->toStartWith('ssh-ed25519 ')
                ->toEndWith(' owner@example.com');
        });
    } finally {
        forgetTestEnvironmentVariable('APP_KEY');
    }
});

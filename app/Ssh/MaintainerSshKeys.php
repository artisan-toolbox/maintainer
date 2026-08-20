<?php

declare(strict_types=1);

namespace ArtisanToolbox\Maintainer\Ssh;

use ArtisanToolbox\Maintainer\Encryption\MaintainerEncrypterFactory;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\StringEncrypter;
use phpseclib3\Crypt\EC\PrivateKey;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

final readonly class MaintainerSshKeys
{
    /**
     * @param  Closure(): StringEncrypter  $encrypterResolver
     * @param  Closure(): mixed  $encryptedPrivateKeyResolver
     */
    private function __construct(
        private Closure $encrypterResolver,
        private Closure $encryptedPrivateKeyResolver,
    ) {}

    public static function fromValues(StringEncrypter $encrypter, string $encryptedPrivateKey): self
    {
        return self::fromResolvers(
            static fn (): StringEncrypter => $encrypter,
            static fn (): string => $encryptedPrivateKey,
        );
    }

    /**
     * @param  Closure(): StringEncrypter  $encrypterResolver
     * @param  Closure(): mixed  $encryptedPrivateKeyResolver
     */
    public static function fromResolvers(Closure $encrypterResolver, Closure $encryptedPrivateKeyResolver): self
    {
        return new self($encrypterResolver, $encryptedPrivateKeyResolver);
    }

    public static function fromLaravelContainer(Container $container): self
    {
        $configuration = $container->make(Repository::class);

        return self::fromResolvers(
            static fn (): StringEncrypter => MaintainerEncrypterFactory::make(
                $configuration->get('maintainer_secrets.key', $configuration->get('app.key')),
            ),
            static fn (): mixed => $configuration->get('maintainer_secrets.rsa_key'),
        );
    }

    public function privateKey(): string
    {
        $encryptedPrivateKey = ($this->encryptedPrivateKeyResolver)();

        throw_unless(is_string($encryptedPrivateKey) && $encryptedPrivateKey !== '', RuntimeException::class, 'Maintainer secrets do not contain an encrypted rsa_key. Publish the Maintainer secrets configuration to generate one.');

        return ($this->encrypterResolver)()->decryptString($encryptedPrivateKey);
    }

    public function publicKey(): string
    {
        $privateKey = PublicKeyLoader::loadPrivateKey($this->privateKey());

        throw_unless($privateKey instanceof PrivateKey, RuntimeException::class, 'The configured Maintainer SSH private key is not an Ed25519 key.');

        $comment = $privateKey->getComment();
        $options = is_string($comment) && $comment !== ''
            ? ['comment' => $comment]
            : [];

        $publicKey = $privateKey->getPublicKey()->toString('OpenSSH', $options);

        throw_unless(str_starts_with($publicKey, 'ssh-ed25519 '), RuntimeException::class, 'The configured Maintainer SSH private key is not an Ed25519 key.');

        return $publicKey;
    }
}

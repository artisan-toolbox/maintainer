<?php

namespace App\Support\Configuration;

use App\Support\Ssh\Ed25519KeyGenerator;
use ArtisanToolbox\Maintainer\Encryption\MaintainerEncrypterFactory;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class MaintainerSecretsTemplate
{
    public function __construct(
        private Filesystem $files,
        private Ed25519KeyGenerator $keyGenerator,
        private DefaultMaintainerSecrets $defaults,
    ) {}

    public function contents(string $email): string
    {
        $template = config_path('maintainer_secrets.php');

        throw_unless($this->files->isFile($template), RuntimeException::class, 'The Maintainer secrets configuration template could not be found.');

        $encryptedPrivateKey = MaintainerEncrypterFactory::make($this->defaults->all()['key'] ?? null)->encryptString(
            $this->keyGenerator->generatePrivateKey($email),
        );
        $replacement = "'rsa_key' => ".var_export($encryptedPrivateKey, true).',';
        $contents = str_replace("'rsa_key' => null,", $replacement, $this->files->get($template), $replacements);

        throw_unless($replacements === 1, RuntimeException::class, 'The Maintainer secrets template does not contain the expected rsa_key placeholder.');

        return $contents;
    }
}

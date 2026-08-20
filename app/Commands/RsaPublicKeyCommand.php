<?php

namespace App\Commands;

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

#[Signature('rsa:public')]
#[Description('Derive and print the Maintainer SSH Ed25519 public key')]
final class RsaPublicKeyCommand extends Command
{
    public function handle(MaintainerSshKeys $keys): int
    {
        try {
            $publicKey = $keys->publicKey();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln($publicKey);

        return self::SUCCESS;
    }
}

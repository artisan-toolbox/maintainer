<?php

namespace App\Commands\Configuration;

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

#[Signature('ssh:key')]
#[Description('Print the decrypted Maintainer SSH Ed25519 private key')]
final class SshPrivateKeyCommand extends Command
{
    public function handle(MaintainerSshKeys $keys): int
    {
        try {
            $privateKey = $keys->privateKey();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln($privateKey);

        return self::SUCCESS;
    }
}

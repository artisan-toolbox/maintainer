<?php

namespace App\Support\Ssh;

use phpseclib3\Crypt\EC;

final readonly class Ed25519KeyGenerator
{
    public function generatePrivateKey(string $email): string
    {
        return EC::createKey('Ed25519')->toString('OpenSSH', [
            'comment' => $email,
        ]);
    }
}

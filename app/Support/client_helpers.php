<?php

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Illuminate\Container\Container;

if (! function_exists('maintainer_rsa_key')) {
    /**
     * Retrieve the decrypted Maintainer SSH Ed25519 private key.
     */
    function maintainer_rsa_key(): string
    {
        return MaintainerSshKeys::fromLaravelContainer(Container::getInstance())->privateKey();
    }
}

if (! function_exists('maintainer_rsa_public_key')) {
    /**
     * Derive and retrieve the Maintainer SSH Ed25519 public key.
     */
    function maintainer_rsa_public_key(): string
    {
        return MaintainerSshKeys::fromLaravelContainer(Container::getInstance())->publicKey();
    }
}

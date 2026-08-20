<?php

use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Illuminate\Container\Container;

if (! function_exists('maintainer_ssh_key')) {
    /**
     * Retrieve the decrypted Maintainer SSH Ed25519 private key.
     */
    function maintainer_ssh_key(): string
    {
        return MaintainerSshKeys::fromLaravelContainer(Container::getInstance())->privateKey();
    }
}

if (! function_exists('maintainer_ssh_public_key')) {
    /**
     * Derive and retrieve the Maintainer SSH Ed25519 public key.
     */
    function maintainer_ssh_public_key(): string
    {
        return MaintainerSshKeys::fromLaravelContainer(Container::getInstance())->publicKey();
    }
}

<?php

namespace App\Support\Deployer;

use App\Support\Configuration\MaintainerSecrets;
use ArtisanToolbox\Maintainer\Ssh\MaintainerSshKeys;
use Closure;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

final readonly class TemporarySshIdentityFile
{
    public function __construct(
        private Filesystem $files,
        private MaintainerSecrets $secrets,
        private MaintainerSshKeys $keys,
    ) {}

    /**
     * Run an operation with the decrypted SSH identity available as a temporary file.
     *
     * @template TResult
     *
     * @param  Closure(?string): TResult  $callback
     * @return TResult
     */
    public function using(Closure $callback): mixed
    {
        if (! $this->secrets->hasSshKey()) {
            return $callback(null);
        }

        $privateKey = $this->keys->privateKey();
        $path = tempnam(sys_get_temp_dir(), 'maintainer-ssh-');

        throw_if($path === false, RuntimeException::class, 'Unable to create a temporary SSH identity file.');

        try {
            if (PHP_OS_FAMILY !== 'Windows') {
                throw_unless(chmod($path, 0600), RuntimeException::class, 'Unable to restrict the temporary SSH identity file permissions.');
            }

            throw_if($this->files->put($path, $privateKey) === false, RuntimeException::class, 'Unable to write the temporary SSH identity file.');

            return $callback($path);
        } finally {
            $this->files->delete($path);
        }
    }
}

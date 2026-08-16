<?php

namespace App\Support\Configuration;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

#[Singleton]
final readonly class DefaultMaintainerSecrets
{
    public function __construct(
        private Filesystem $files,
        private JsonTemplateFormatter $formatter,
    ) {}

    public function contents(): string
    {
        $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'maintainer_secrets.json';

        throw_unless($this->files->isFile($path), RuntimeException::class, 'The default Maintainer secrets file could not be found.');

        return $this->formatter->format(
            $this->files->get($path),
            'Maintainer secrets file',
        );
    }
}

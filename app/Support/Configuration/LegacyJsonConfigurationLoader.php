<?php

namespace App\Support\Configuration;

use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use stdClass;

final readonly class LegacyJsonConfigurationLoader
{
    public function __construct(private Filesystem $files) {}

    /**
     * @return array<string, mixed>
     */
    public function load(string $path, string $filename): array
    {
        $contents = $this->files->get($path);

        try {
            $decodedObject = json_decode($contents, flags: JSON_THROW_ON_ERROR);
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "{$filename} contains invalid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        throw_unless(
            $decodedObject instanceof stdClass && is_array($decoded),
            RuntimeException::class,
            "{$filename} must contain a JSON object.",
        );

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}

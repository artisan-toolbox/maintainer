<?php

namespace App\Support\Configuration;

use JsonException;
use RuntimeException;
use stdClass;

final class JsonTemplateFormatter
{
    public function format(string $contents, string $name): string
    {
        try {
            $decoded = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "The default {$name} contains invalid JSON: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        throw_unless($decoded instanceof stdClass, RuntimeException::class, "The default {$name} must contain a JSON object.");

        try {
            return json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ).PHP_EOL;
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "The default {$name} could not be formatted: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }
}

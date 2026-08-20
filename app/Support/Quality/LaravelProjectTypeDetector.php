<?php

namespace App\Support\Quality;

use Illuminate\Filesystem\Filesystem;
use JsonException;

use function Illuminate\Filesystem\join_paths;

final readonly class LaravelProjectTypeDetector
{
    public function __construct(private Filesystem $files) {}

    public function detect(string $projectRoot): LaravelProjectType
    {
        try {
            $decoded = json_decode(
                $this->files->get(join_paths($projectRoot, 'composer.json')),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return LaravelProjectType::Application;
        }

        if (! is_array($decoded)) {
            return LaravelProjectType::Application;
        }

        /** @var array{type?: mixed} $composer */
        $composer = $decoded;

        return ($composer['type'] ?? null) === 'project'
            ? LaravelProjectType::Application
            : LaravelProjectType::Package;
    }
}

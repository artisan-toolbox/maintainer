<?php

namespace App\Support\Quality;

use Illuminate\Filesystem\Filesystem;

use function Illuminate\Filesystem\join_paths;

final readonly class QualityConfigurationManager
{
    public function __construct(private Filesystem $files) {}

    public function find(QualityTool $tool, string $projectRoot): ?string
    {
        foreach ($tool->configurationFilenames() as $filename) {
            $path = join_paths($projectRoot, $filename);

            if ($this->files->isFile($path)) {
                return $path;
            }
        }

        return null;
    }
}

<?php

namespace App\Support\Release;

use App\Support\Git\GitWorkingTree;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use RuntimeException;
use stdClass;

use function Illuminate\Filesystem\join_paths;

final readonly class PackageJsonVersionWriter
{
    public function __construct(
        private Filesystem $files,
        private GitWorkingTree $workingTree,
    ) {}

    public function canUpdate(string $projectRoot): bool
    {
        $path = join_paths($projectRoot, 'package.json');

        return $this->files->isFile($path)
            && ! is_link($path)
            && $this->workingTree->isTracked($projectRoot, 'package.json');
    }

    public function write(string $projectRoot, string $version): void
    {
        $path = join_paths($projectRoot, 'package.json');
        $contents = $this->files->get($path);

        try {
            $manifest = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid package.json. '.$exception->getMessage(), previous: $exception);
        }

        throw_unless($manifest instanceof stdClass, RuntimeException::class, 'package.json must contain a JSON object.');

        if (($manifest->version ?? null) === $version) {
            return;
        }

        $manifest->version = $version;
        $updated = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        preg_match('/\R([ \t]+)"/', $contents, $indentation);
        $indent = $indentation[1] ?? '  ';
        $updated = preg_replace_callback('/^ +/m', static fn (array $match): string => str_repeat($indent, intdiv(strlen($match[0]), 4)), $updated) ?? $updated;
        $updated .= str_ends_with($contents, "\n") ? "\n" : '';

        if (str_contains($contents, "\r\n")) {
            $updated = str_replace("\n", "\r\n", $updated);
        }

        throw_if($this->files->put($path, $updated) !== strlen($updated), RuntimeException::class, 'Unable to write package.json.');
    }
}

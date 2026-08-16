<?php

namespace App\Support;

use ArtisanToolbox\Maintainer\Contracts\Versionable\Versionable;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use PhpParser\Error;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RuntimeException;
use SplFileInfo;

final readonly class VersionableImplementation
{
    private Parser $parser;

    public function __construct(private Filesystem $files)
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Find a class directly in a production PSR-4 namespace that implements Versionable.
     */
    public function find(string $projectRoot): ?VersionableClass
    {
        $incompleteImplementation = null;

        foreach ($this->psrFourMappings($projectRoot) as $namespace => $directories) {
            foreach ($directories as $directory) {
                foreach ($this->phpFiles($projectRoot, $directory) as $file) {
                    foreach ($this->implementationsIn($file, $namespace) as $implementation) {
                        if ($implementation->hasVersionConstant) {
                            return $implementation;
                        }

                        $incompleteImplementation ??= $implementation;
                    }
                }
            }
        }

        return $incompleteImplementation;
    }

    /**
     * @return array<string, list<string>>
     */
    private function psrFourMappings(string $projectRoot): array
    {
        $manifestPath = $projectRoot.DIRECTORY_SEPARATOR.'composer.json';

        try {
            $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The project composer.json contains invalid JSON.', previous: $exception);
        }

        $mappings = $manifest['autoload']['psr-4'] ?? [];

        if (! is_array($mappings)) {
            return [];
        }

        $normalized = [];

        foreach ($mappings as $namespace => $directories) {
            if (! is_string($namespace)) {
                continue;
            }

            $directories = is_string($directories) ? [$directories] : $directories;

            if (! is_array($directories)) {
                continue;
            }

            $normalized[rtrim($namespace, '\\')] = array_values(array_filter(
                $directories,
                is_string(...),
            ));
        }

        return $normalized;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFiles(string $projectRoot, string $directory): array
    {
        $path = $this->isAbsolutePath($directory)
            ? $directory
            : $projectRoot.DIRECTORY_SEPARATOR.$directory;

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        return array_values(array_filter(
            $this->files->files($path),
            static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        ));
    }

    /**
     * @return list<VersionableClass>
     */
    private function implementationsIn(SplFileInfo $file, string $baseNamespace): array
    {
        try {
            $statements = $this->parser->parse($this->files->get($file->getPathname())) ?? [];
        } catch (Error $exception) {
            throw new RuntimeException(
                "Unable to inspect {$file->getPathname()}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $statements = $traverser->traverse($statements);

        $classes = (new NodeFinder)->findInstanceOf($statements, Class_::class);
        $implementations = [];

        foreach ($classes as $class) {
            if ($class->isAnonymous()) {
                continue;
            }

            $className = $class->namespacedName?->toString();

            if ($className === null || $this->namespaceOf($className) !== $baseNamespace) {
                continue;
            }

            foreach ($class->implements as $interface) {
                if ($interface->toString() === Versionable::class) {
                    $implementations[] = new VersionableClass(
                        $className,
                        $this->hasVersionConstant($class),
                    );
                }
            }
        }

        return $implementations;
    }

    private function hasVersionConstant(Class_ $class): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof ClassConst
                || ! $statement->isPublic()
                || ! $statement->type instanceof Identifier
                || strtolower($statement->type->toString()) !== 'string') {
                continue;
            }

            foreach ($statement->consts as $constant) {
                if ($constant->name->toString() === 'VERSION') {
                    return true;
                }
            }
        }

        return false;
    }

    private function namespaceOf(string $className): string
    {
        $separator = strrpos($className, '\\');

        return $separator === false ? '' : substr($className, 0, $separator);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}

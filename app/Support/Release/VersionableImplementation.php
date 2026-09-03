<?php

namespace App\Support\Release;

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Error;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;
use SplFileInfo;

use function Illuminate\Filesystem\join_paths;

final readonly class VersionableImplementation
{
    private Parser $parser;

    public function __construct(private Filesystem $files)
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * Find a class exposed by the production Composer autoloader that implements Versionable.
     */
    public function find(string $projectRoot): ?VersionableClass
    {
        $invalidImplementation = null;
        $implementationWithoutVersion = null;

        foreach ($this->autoloadedPhpFiles($projectRoot) as [$file, $baseNamespace]) {
            foreach ($this->implementationsIn($file, $baseNamespace) as $implementation) {
                if ($implementation->version !== null) {
                    return $implementation;
                }

                if ($implementation->hasVersionConstant) {
                    $invalidImplementation ??= $implementation;
                } else {
                    $implementationWithoutVersion ??= $implementation;
                }
            }
        }

        return $invalidImplementation ?? $implementationWithoutVersion;
    }

    /**
     * @return list<array{SplFileInfo, string|null}>
     */
    private function autoloadedPhpFiles(string $projectRoot): array
    {
        $files = [];

        foreach ($this->psrFourMappings($projectRoot) as $namespace => $directories) {
            foreach ($directories as $directory) {
                foreach ($this->phpFiles($projectRoot, $directory) as $file) {
                    $files[$file->getPathname()] = [$file, $namespace];
                }
            }
        }

        foreach ($this->classmapEntries($projectRoot) as $entry) {
            foreach ($this->classmapPhpFiles($projectRoot, $entry) as $file) {
                $files[$file->getPathname()] = [$file, null];
            }
        }

        return array_values($files);
    }

    /**
     * @return array<string, list<string>>
     */
    private function psrFourMappings(string $projectRoot): array
    {
        $manifestPath = join_paths($projectRoot, 'composer.json');

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
     * @return list<string>
     */
    private function classmapEntries(string $projectRoot): array
    {
        $manifestPath = join_paths($projectRoot, 'composer.json');

        try {
            $manifest = json_decode($this->files->get($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The project composer.json contains invalid JSON.', previous: $exception);
        }

        $entries = $manifest['autoload']['classmap'] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, is_string(...)));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFiles(string $projectRoot, string $directory): array
    {
        $path = $this->isAbsolutePath($directory)
            ? $directory
            : join_paths($projectRoot, $directory);

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        return array_values(array_filter(
            $this->files->files($path),
            static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        ));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function classmapPhpFiles(string $projectRoot, string $entry): array
    {
        $path = $this->isAbsolutePath($entry)
            ? $entry
            : join_paths($projectRoot, $entry);

        if ($this->files->isFile($path)) {
            return pathinfo($path, PATHINFO_EXTENSION) === 'php'
                ? [new SplFileInfo($path)]
                : [];
        }

        if (! $this->files->isDirectory($path)) {
            return [];
        }

        return array_values(array_filter(
            $this->files->allFiles($path),
            static fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
        ));
    }

    /**
     * @return list<VersionableClass>
     */
    private function implementationsIn(SplFileInfo $file, ?string $baseNamespace): array
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

            if ($className === null
                || ($baseNamespace !== null && $this->namespaceOf($className) !== $baseNamespace)) {
                continue;
            }

            foreach ($class->implements as $interface) {
                if ($interface->toString() === Versionable::class) {
                    [$hasVersionConstant, $version] = $this->version($class);

                    $implementations[] = new VersionableClass(
                        $className,
                        $file->getPathname(),
                        $hasVersionConstant,
                        $version,
                        array_values(array_map(
                            static fn ($implemented): string => $implemented->toString(),
                            $class->implements,
                        )),
                    );
                }
            }
        }

        return $implementations;
    }

    /**
     * @return array{bool, string|null}
     */
    private function version(Class_ $class): array
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof ClassConst) {
                continue;
            }

            foreach ($statement->consts as $constant) {
                if ($constant->name->toString() !== 'VERSION') {
                    continue;
                }

                if (! $statement->isPublic()
                    || ! $statement->type instanceof Identifier
                    || strtolower($statement->type->toString()) !== 'string') {
                    return [true, null];
                }

                try {
                    $value = (new ConstExprEvaluator)->evaluateSilently($constant->value);

                    if (is_string($value)) {
                        return [true, $value];
                    }
                } catch (ConstExprEvaluationException) {
                    // Preserve the declared expression when it needs project context to resolve.
                }

                return [true, (new Standard)->prettyPrintExpr($constant->value)];
            }
        }

        return [false, null];
    }

    private function namespaceOf(string $className): string
    {
        $separator = strrpos($className, '\\');

        return $separator === false ? '' : substr($className, 0, $separator);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[\\\\\/]|[A-Za-z]:[\\\\\/])/', $path) === 1;
    }
}

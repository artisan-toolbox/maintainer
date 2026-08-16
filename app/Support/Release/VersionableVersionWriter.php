<?php

namespace App\Support\Release;

use Illuminate\Filesystem\Filesystem;
use PhpParser\Error;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Const_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

final readonly class VersionableVersionWriter
{
    private Parser $parser;

    public function __construct(private Filesystem $files)
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    public function write(VersionableClass $versionableClass, string $version): void
    {
        $contents = $this->files->get($versionableClass->file);

        try {
            $originalStatements = $this->parser->parse($contents) ?? [];
        } catch (Error $exception) {
            throw new RuntimeException(
                "Unable to update {$versionableClass->file}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $tokens = $this->parser->getTokens();
        $cloner = new NodeTraverser;
        $cloner->addVisitor(new CloningVisitor);
        $statements = $cloner->traverse($originalStatements);

        $resolver = new NodeTraverser;
        $resolver->addVisitor(new NameResolver(options: ['replaceNodes' => false]));
        $statements = $resolver->traverse($statements);

        $class = $this->findClass($statements, $versionableClass->name);

        if ($class === null) {
            throw new RuntimeException("Unable to find {$versionableClass->name} in {$versionableClass->file}.");
        }

        if (! $this->replaceVersion($class, $version)) {
            array_unshift($class->stmts, new ClassConst(
                [new Const_('VERSION', new String_($version))],
                Modifiers::PUBLIC,
                type: new Identifier('string'),
            ));
        }

        $updatedContents = (new Standard)->printFormatPreserving(
            $statements,
            $originalStatements,
            $tokens,
        );

        if ($this->files->put($versionableClass->file, $updatedContents) === false) {
            throw new RuntimeException("Unable to write {$versionableClass->file}.");
        }
    }

    /**
     * @param  array<Node>  $statements
     */
    private function findClass(array $statements, string $className): ?Class_
    {
        $classes = (new NodeFinder)->findInstanceOf($statements, Class_::class);

        foreach ($classes as $class) {
            if ($class->namespacedName?->toString() === $className) {
                return $class;
            }
        }

        return null;
    }

    private function replaceVersion(Class_ $class, string $version): bool
    {
        foreach ($class->stmts as $statement) {
            if (! $statement instanceof ClassConst) {
                continue;
            }

            foreach ($statement->consts as $constant) {
                if ($constant->name->toString() === 'VERSION') {
                    $constant->value = new String_($version);

                    return true;
                }
            }
        }

        return false;
    }
}

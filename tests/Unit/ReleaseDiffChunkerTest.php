<?php

use App\Support\Ai\ReleaseDiffChunker;

it('bounds release diff fragments and omits generated or dependency files', function () {
    $diff = <<<'DIFF'
diff --git a/src/Feature.php b/src/Feature.php
index 1111111..2222222 100644
--- a/src/Feature.php
+++ b/src/Feature.php
@@ -1 +1,4 @@
+A backward-compatible feature with enough detail to require more than one bounded fragment.
+Another meaningful source line.
+A final meaningful source line.
diff --git a/composer.lock b/composer.lock
index 3333333..4444444 100644
--- a/composer.lock
+++ b/composer.lock
@@ -1 +1 @@
-old dependency graph
+large generated dependency graph
DIFF;

    $context = new ReleaseDiffChunker(maxCharacters: 120, maxChunks: 10)->chunk($diff);

    expect($context->chunks)->not->toBeEmpty()
        ->and(collect($context->chunks)->every(fn (string $chunk): bool => strlen($chunk) <= 120))->toBeTrue()
        ->and($context->omittedFiles)->toBe(['composer.lock'])
        ->and(implode("\n", $context->chunks))->not->toContain('dependency graph')
        ->and($context->truncated)->toBeFalse();
});

it('caps the number of fragments sent to AI', function () {
    $diff = implode("\n", array_map(
        fn (int $number): string => "diff --git a/src/File{$number}.php b/src/File{$number}.php\n+change {$number}",
        range(1, 5),
    ));

    $context = new ReleaseDiffChunker(maxCharacters: 100, maxChunks: 2)->chunk($diff);

    expect($context->chunks)->toHaveCount(2)
        ->and($context->truncated)->toBeTrue();
});

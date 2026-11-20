<?php

use App\Support\Ai\AiReportLocale;
use Illuminate\Filesystem\Filesystem;

it('uses the configured language or the effective terminal locale for the recommendation', function (?string $configured, string $system, string $expected) {
    withinTemporaryProject(function (string $directory, Filesystem $files) use ($configured, $system, $expected) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => $configured],
        ]);
        $original = getenv('LC_ALL');
        putenv("LC_ALL={$system}");

        try {
            $locales = resolve(AiReportLocale::class);
            expect($locales->forReleaseRecommendation())->toBe($expected)
                ->and($locales->forProjectArtifacts())->toBe('en');
        } finally {
            putenv($original === false ? 'LC_ALL' : "LC_ALL={$original}");
        }
    });
})->with([
    'explicit override' => ['pt_BR', 'en_US.UTF-8', 'pt_BR'],
    'POSIX locale with charset' => [null, 'es_AR.UTF-8', 'es_AR'],
    'English fallback for C locale' => [null, 'C.UTF-8', 'en'],
]);

it('rejects a malformed explicitly configured report language', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => 'use any language'],
        ]);

        expect(fn () => resolve(AiReportLocale::class)->forReleaseRecommendation())
            ->toThrow(RuntimeException::class, 'ai.local_feedback_locale');
    });
});

it('keeps the local feedback and project languages independent', function () {
    withinTemporaryProject(function (string $directory, Filesystem $files) {
        putPhpConfiguration($files, $directory.'/config/dev_maintainer.php', [
            'ai' => ['local_feedback_locale' => 'pt_BR', 'project_locale' => 'es_AR'],
        ]);
        $locales = resolve(AiReportLocale::class);

        expect($locales->forReleaseRecommendation())->toBe('pt_BR')
            ->and($locales->forProjectArtifacts())->toBe('es_AR');
    });
});

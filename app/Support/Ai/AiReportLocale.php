<?php

namespace App\Support\Ai;

use App\Support\Configuration\MaintainerConfiguration;
use Illuminate\Support\Facades\Process;
use Locale;
use RuntimeException;

final readonly class AiReportLocale
{
    private const string FALLBACK_LOCALE = 'en';

    public function __construct(
        private MaintainerConfiguration $configuration,
        private string $operatingSystem = PHP_OS_FAMILY,
    ) {}

    public function forReleaseRecommendation(): string
    {
        $configured = $this->configuration->get('ai.local_feedback_locale');

        if ($configured !== null) {
            return $this->configuredLocale('ai.local_feedback_locale', $configured);
        }

        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && trim($value) !== '') {
                return $this->normalize($value) ?? $this->macOsPreferredLocale() ?? self::FALLBACK_LOCALE;
            }
        }

        return $this->macOsPreferredLocale()
            ?? (class_exists(Locale::class) ? $this->normalize(Locale::getDefault()) : null)
            ?? self::FALLBACK_LOCALE;
    }

    public function forProjectArtifacts(): string
    {
        return $this->configuredLocale(
            'ai.project_locale',
            $this->configuration->get('ai.project_locale', self::FALLBACK_LOCALE),
        );
    }

    private function macOsPreferredLocale(): ?string
    {
        if ($this->operatingSystem !== 'Darwin') {
            return null;
        }

        try {
            $preferences = Process::timeout(1)->run(['/usr/bin/defaults', 'read', '-g', 'AppleLanguages']);
        } catch (RuntimeException) {
            return null;
        }

        if ($preferences->failed() || preg_match('/\A\s*\(\s*"?([a-z0-9_-]+)"?\s*[,)]/i', $preferences->output(), $matches) !== 1) {
            return null;
        }

        return $this->normalize($matches[1]);
    }

    private function configuredLocale(string $key, mixed $configured): string
    {
        $locale = is_string($configured) ? $this->normalize($configured) : null;
        throw_if($locale === null, RuntimeException::class, "{$key} must be a locale such as pt_BR or en.");

        return $locale;
    }

    private function normalize(string $value): ?string
    {
        $pattern = '/\A(?<language>[a-z]{2,3})(?:[_-](?<script>[a-z]{4}))?(?:[_-](?<region>[a-z]{2}|[0-9]{3}))?(?:[.@][a-z0-9._@-]+)?\z/i';

        if (preg_match($pattern, trim($value), $parts, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $language = strtolower($parts['language']);
        $script = $parts['script'] === null ? '' : '_'.ucfirst(strtolower($parts['script']));
        $region = $parts['region'] === null ? '' : '_'.strtoupper($parts['region']);

        return $language.$script.$region;
    }
}

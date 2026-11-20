<?php

namespace App\Support\Ai;

use App\Support\Configuration\MaintainerConfiguration;
use Locale;
use RuntimeException;

final readonly class AiReportLocale
{
    private const string FALLBACK_LOCALE = 'en';

    public function __construct(private MaintainerConfiguration $configuration) {}

    public function forReleaseRecommendation(): string
    {
        $configured = $this->configuration->get('ai.local_feedback_locale');

        if ($configured !== null) {
            return $this->configuredLocale('ai.local_feedback_locale', $configured);
        }

        foreach (['LC_ALL', 'LC_MESSAGES', 'LANG'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && trim($value) !== '') {
                return $this->normalize($value) ?? self::FALLBACK_LOCALE;
            }
        }

        return (class_exists(Locale::class) ? $this->normalize(Locale::getDefault()) : null)
            ?? self::FALLBACK_LOCALE;
    }

    public function forProjectArtifacts(): string
    {
        return $this->configuredLocale(
            'ai.project_locale',
            $this->configuration->get('ai.project_locale', self::FALLBACK_LOCALE),
        );
    }

    private function configuredLocale(string $key, mixed $configured): string
    {
        $locale = is_string($configured) ? $this->normalize($configured) : null;
        throw_if($locale === null, RuntimeException::class, "{$key} must be a locale such as pt_BR or en.");

        return $locale;
    }

    private function normalize(string $value): ?string
    {
        if (preg_match('/\A([a-z]{2,3})(?:[_-]([a-z]{2}|[0-9]{3}))?(?:[.@][a-z0-9._@-]+)?\z/i', trim($value), $parts) !== 1) {
            return null;
        }

        return strtolower($parts[1]).(isset($parts[2]) ? '_'.strtoupper($parts[2]) : '');
    }
}

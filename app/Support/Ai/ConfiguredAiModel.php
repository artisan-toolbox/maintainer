<?php

namespace App\Support\Ai;

use App\Support\Configuration\MaintainerConfiguration;
use RuntimeException;

final readonly class ConfiguredAiModel
{
    private const string DEFAULT_OPENAI_MODEL = 'gpt-6.2-sol';

    public function __construct(private MaintainerConfiguration $configuration) {}

    public function for(string $purpose, string $provider): ?string
    {
        $model = $this->configuration->get("ai.models.{$purpose}");

        if ($model === null) {
            return $provider === AiProvider::OpenAi->value ? self::DEFAULT_OPENAI_MODEL : null;
        }

        throw_unless(
            is_string($model) && trim($model) !== '',
            RuntimeException::class,
            "ai.models.{$purpose} must be a nonempty model ID or null.",
        );

        return trim($model);
    }
}

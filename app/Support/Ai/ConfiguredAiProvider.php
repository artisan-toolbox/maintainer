<?php

namespace App\Support\Ai;

use App\Support\Configuration\MaintainerConfiguration;
use App\Support\Configuration\MaintainerSecrets;
use Illuminate\Contracts\Config\Repository;
use Laravel\Ai\AiManager;
use RuntimeException;

final readonly class ConfiguredAiProvider
{
    public function __construct(
        private MaintainerConfiguration $configuration,
        private MaintainerSecrets $secrets,
        private Repository $laravelConfiguration,
        private AiManager $ai,
    ) {}

    public function for(string $purpose): string
    {
        $configured = $this->configuration->get("ai.providers.{$purpose}");

        throw_unless(is_string($configured), RuntimeException::class, "ai.providers.{$purpose} must be a valid Laravel AI provider name.");

        $provider = AiProvider::tryFrom($configured);

        throw_if($provider === null, RuntimeException::class, "The {$configured} AI provider configured for {$purpose} is not supported by Laravel AI.");
        throw_unless($provider->supportsText(), RuntimeException::class, "The {$configured} AI provider configured for {$purpose} does not support text generation.");

        $credentials = $this->secrets->aiProvider($provider->value);
        $existing = $this->laravelConfiguration->get("ai.providers.{$provider->value}", []);

        throw_unless(is_array($existing), RuntimeException::class, "Laravel AI configuration for {$provider->value} is invalid.");

        $this->laravelConfiguration->set(
            "ai.providers.{$provider->value}",
            array_replace_recursive($existing, $credentials),
        );
        $this->ai->forgetInstance($provider->value);

        return $provider->value;
    }
}

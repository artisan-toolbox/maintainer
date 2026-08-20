<?php

return [
    'key' => env('APP_KEY'),

    'rsa_key' => null,

    'ai_providers' => [
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY', ''),
        ],
        'azure' => [
            'key' => env('AZURE_OPENAI_API_KEY', ''),
            'url' => env('AZURE_OPENAI_URL', ''),
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-4o'),
        ],
        'bedrock' => [
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            'key' => env('AWS_BEARER_TOKEN_BEDROCK', ''),
            'access_key_id' => env('AWS_ACCESS_KEY_ID', ''),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY', ''),
            'session_token' => env('AWS_SESSION_TOKEN', ''),
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
        ],
        'cohere' => [
            'key' => env('COHERE_API_KEY', ''),
        ],
        'deepseek' => [
            'key' => env('DEEPSEEK_API_KEY', ''),
        ],
        'eleven' => [
            'key' => env('ELEVENLABS_API_KEY', ''),
        ],
        'gemini' => [
            'key' => env('GEMINI_API_KEY', ''),
        ],
        'groq' => [
            'key' => env('GROQ_API_KEY', ''),
        ],
        'jina' => [
            'key' => env('JINA_API_KEY', ''),
        ],
        'mistral' => [
            'key' => env('MISTRAL_API_KEY', ''),
        ],
        'ollama' => [
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],
        'openai' => [
            'key' => env('OPENAI_API_KEY', ''),
        ],
        'openai-compatible' => [
            'key' => env('OPENAI_COMPATIBLE_API_KEY', ''),
            'url' => env('OPENAI_COMPATIBLE_URL', ''),
        ],
        'openrouter' => [
            'key' => env('OPENROUTER_API_KEY', ''),
        ],
        'voyageai' => [
            'key' => env('VOYAGEAI_API_KEY', ''),
        ],
        'xai' => [
            'key' => env('XAI_API_KEY', ''),
        ],
    ],
];

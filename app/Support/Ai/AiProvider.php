<?php

namespace App\Support\Ai;

enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case Azure = 'azure';
    case Bedrock = 'bedrock';
    case Cohere = 'cohere';
    case DeepSeek = 'deepseek';
    case Eleven = 'eleven';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case Jina = 'jina';
    case Mistral = 'mistral';
    case Ollama = 'ollama';
    case OpenAi = 'openai';
    case OpenAiCompatible = 'openai-compatible';
    case OpenRouter = 'openrouter';
    case VoyageAi = 'voyageai';
    case Xai = 'xai';

    public function supportsText(): bool
    {
        return match ($this) {
            self::Anthropic,
            self::Azure,
            self::Bedrock,
            self::DeepSeek,
            self::Gemini,
            self::Groq,
            self::Mistral,
            self::Ollama,
            self::OpenAi,
            self::OpenAiCompatible,
            self::OpenRouter,
            self::Xai => true,
            self::Cohere,
            self::Eleven,
            self::Jina,
            self::VoyageAi => false,
        };
    }
}

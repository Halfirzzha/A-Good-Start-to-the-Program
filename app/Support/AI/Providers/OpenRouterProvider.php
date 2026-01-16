<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * OpenRouter Provider (Multi-model gateway)
 *
 * Access to 100+ AI models through a single API.
 * Best for: Fallback provider, access to diverse models.
 *
 * Includes FREE models like:
 * - google/gemma-2-9b-it:free
 * - meta-llama/llama-3.2-3b-instruct:free
 */
class OpenRouterProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    protected ?string $siteUrl = null;

    protected ?string $siteName = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 40;
    }

    public function getIdentifier(): string
    {
        return 'openrouter';
    }

    public function getName(): string
    {
        return 'OpenRouter (Multi-Model)';
    }

    public function isConfigured(): bool
    {
        return ! empty($this->getApiKey());
    }

    protected function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    public function setSiteInfo(?string $url, ?string $name): void
    {
        $this->siteUrl = $url;
        $this->siteName = $name;
    }

    protected function getApiEndpoint(): string
    {
        return 'https://openrouter.ai/api/v1/chat/completions';
    }

    public function getModels(): array
    {
        return [
            // FREE MODELS
            'google/gemma-2-9b-it:free' => [
                'name' => 'Gemma 2 9B (FREE)',
                'cost_per_1k' => 0,
                'max_tokens' => 8192,
                'recommended_for' => 'Free tier, good quality',
            ],
            'meta-llama/llama-3.2-3b-instruct:free' => [
                'name' => 'Llama 3.2 3B (FREE)',
                'cost_per_1k' => 0,
                'max_tokens' => 8192,
                'recommended_for' => 'Free tier, fast',
            ],
            'microsoft/phi-3-mini-128k-instruct:free' => [
                'name' => 'Phi-3 Mini (FREE)',
                'cost_per_1k' => 0,
                'max_tokens' => 4096,
                'recommended_for' => 'Free tier, compact',
            ],
            // PAID MODELS (very cheap)
            'anthropic/claude-3.5-haiku' => [
                'name' => 'Claude 3.5 Haiku',
                'cost_per_1k' => 0.0008,
                'max_tokens' => 8192,
                'recommended_for' => 'High quality, fast',
            ],
            'openai/gpt-4o-mini' => [
                'name' => 'GPT-4o Mini',
                'cost_per_1k' => 0.00015,
                'max_tokens' => 16384,
                'recommended_for' => 'Good quality, affordable',
            ],
            'google/gemini-flash-1.5' => [
                'name' => 'Gemini 1.5 Flash',
                'cost_per_1k' => 0.000075,
                'max_tokens' => 8192,
                'recommended_for' => 'Very fast, very cheap',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'google/gemma-2-9b-it:free'; // FREE!
    }

    /**
     * Get a list of free models.
     *
     * @return array<string>
     */
    public function getFreeModels(): array
    {
        return [
            'google/gemma-2-9b-it:free',
            'meta-llama/llama-3.2-3b-instruct:free',
            'microsoft/phi-3-mini-128k-instruct:free',
        ];
    }

    protected function buildHeaders(string $apiKey): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->siteUrl) {
            $headers['HTTP-Referer'] = $this->siteUrl;
        }
        if ($this->siteName) {
            $headers['X-Title'] = $this->siteName;
        }

        return $headers;
    }

    protected function buildPayload(string $prompt, string $model, array $options): array
    {
        $messages = [];

        if (isset($options['system'])) {
            $messages[] = ['role' => 'system', 'content' => $options['system']];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        return [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? $this->temperature,
        ];
    }

    protected function parseResponse(array $response, float $latencyMs): AIResponse
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            return AIResponse::failure(
                'No content in response',
                $this->getIdentifier(),
                'empty_response',
                $latencyMs
            );
        }

        $usage = $response['usage'] ?? [];
        $inputTokens = $usage['prompt_tokens'] ?? 0;
        $outputTokens = $usage['completion_tokens'] ?? 0;
        $model = $response['model'] ?? $this->getDefaultModel();

        return AIResponse::success(
            content: $content,
            provider: $this->getIdentifier(),
            model: $model,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $this->estimateCost($inputTokens, $outputTokens, $model),
            latencyMs: $latencyMs,
            metadata: ['id' => $response['id'] ?? null],
        );
    }

    protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown OpenRouter error';
        $code = $error['code'] ?? null;

        $errorCode = match ($code) {
            401 => 'invalid_api_key',
            402 => 'insufficient_quota',
            429 => 'rate_limit_exceeded',
            default => match ($statusCode) {
                401 => 'invalid_api_key',
                402 => 'insufficient_quota',
                429 => 'rate_limit_exceeded',
                500, 502, 503 => 'server_error',
                default => 'unknown',
            },
        };

        return AIResponse::failure($message, $this->getIdentifier(), $errorCode, $latencyMs);
    }

    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        $models = $this->getModels();
        $rate = $models[$model]['cost_per_1k'] ?? 0.001;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

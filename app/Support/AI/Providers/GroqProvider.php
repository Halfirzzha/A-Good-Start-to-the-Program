<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * Groq Provider (Llama 3.3, Mixtral)
 *
 * Ultra-fast inference with open-source models.
 * Best for: Speed-critical tasks, cost-effective, good quality.
 *
 * FREE TIER: 14,400 requests/day for some models!
 */
class GroqProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 5; // Highest priority - cheapest and fastest
    }

    public function getIdentifier(): string
    {
        return 'groq';
    }

    public function getName(): string
    {
        return 'Groq (Ultra Fast)';
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

    protected function getApiEndpoint(): string
    {
        return 'https://api.groq.com/openai/v1/chat/completions';
    }

    public function getModels(): array
    {
        return [
            'llama-3.3-70b-versatile' => [
                'name' => 'Llama 3.3 70B',
                'cost_per_1k' => 0.00059,
                'max_tokens' => 32768,
                'recommended_for' => 'Best quality, versatile tasks',
            ],
            'llama-3.1-8b-instant' => [
                'name' => 'Llama 3.1 8B Instant',
                'cost_per_1k' => 0.00005,
                'max_tokens' => 8192,
                'recommended_for' => 'Ultra fast, very cheap',
            ],
            'mixtral-8x7b-32768' => [
                'name' => 'Mixtral 8x7B',
                'cost_per_1k' => 0.00024,
                'max_tokens' => 32768,
                'recommended_for' => 'Good balance of speed and quality',
            ],
            'gemma2-9b-it' => [
                'name' => 'Gemma 2 9B',
                'cost_per_1k' => 0.0002,
                'max_tokens' => 8192,
                'recommended_for' => 'Fast, efficient',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'llama-3.1-8b-instant'; // Ultra fast and cheap
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
            metadata: [
                'id' => $response['id'] ?? null,
                'x_groq' => $response['x_groq'] ?? null,
            ],
        );
    }

    protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown Groq error';
        $type = $error['type'] ?? null;

        $code = match ($type) {
            'invalid_api_key' => 'invalid_api_key',
            'rate_limit_exceeded' => 'rate_limit_exceeded',
            default => match ($statusCode) {
                401 => 'invalid_api_key',
                429 => 'rate_limit_exceeded',
                500, 502, 503 => 'server_error',
                default => 'unknown',
            },
        };

        return AIResponse::failure($message, $this->getIdentifier(), $code, $latencyMs);
    }

    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        $models = $this->getModels();
        $rate = $models[$model]['cost_per_1k'] ?? 0.0001;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

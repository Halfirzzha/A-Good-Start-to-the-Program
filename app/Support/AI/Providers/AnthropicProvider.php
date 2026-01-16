<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * Anthropic Provider (Claude 3.5 Sonnet, Claude 3 Haiku)
 *
 * Excellent for nuanced content, safety-conscious responses.
 * Best for: Professional writing, analysis, safe content generation.
 */
class AnthropicProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 20;
    }

    public function getIdentifier(): string
    {
        return 'anthropic';
    }

    public function getName(): string
    {
        return 'Anthropic (Claude)';
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
        return 'https://api.anthropic.com/v1/messages';
    }

    public function getModels(): array
    {
        return [
            'claude-3-5-sonnet-20241022' => [
                'name' => 'Claude 3.5 Sonnet',
                'cost_per_1k' => 0.003,
                'max_tokens' => 200000,
                'recommended_for' => 'Best overall quality, professional content',
            ],
            'claude-3-5-haiku-20241022' => [
                'name' => 'Claude 3.5 Haiku',
                'cost_per_1k' => 0.0008,
                'max_tokens' => 200000,
                'recommended_for' => 'Fast, cost-effective, good quality',
            ],
            'claude-3-opus-20240229' => [
                'name' => 'Claude 3 Opus',
                'cost_per_1k' => 0.015,
                'max_tokens' => 200000,
                'recommended_for' => 'Most capable, complex reasoning',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'claude-3-5-haiku-20241022'; // Fast and cost-effective
    }

    protected function buildHeaders(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'Content-Type' => 'application/json',
            'anthropic-version' => '2023-06-01',
        ];
    }

    protected function buildPayload(string $prompt, string $model, array $options): array
    {
        $payload = [
            'model' => $model,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if (isset($options['system'])) {
            $payload['system'] = $options['system'];
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        return $payload;
    }

    protected function parseResponse(array $response, float $latencyMs): AIResponse
    {
        $content = $response['content'][0]['text'] ?? null;

        if (! $content) {
            return AIResponse::failure(
                'No content in response',
                $this->getIdentifier(),
                'empty_response',
                $latencyMs
            );
        }

        $usage = $response['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
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
        $message = $error['message'] ?? 'Unknown Anthropic error';
        $type = $error['type'] ?? null;

        $code = match ($type) {
            'authentication_error' => 'invalid_api_key',
            'rate_limit_error' => 'rate_limit_exceeded',
            'overloaded_error' => 'server_error',
            'invalid_request_error' => 'invalid_request',
            default => match ($statusCode) {
                401 => 'invalid_api_key',
                429 => 'rate_limit_exceeded',
                500, 502, 503, 529 => 'server_error',
                default => 'unknown',
            },
        };

        return AIResponse::failure($message, $this->getIdentifier(), $code, $latencyMs);
    }

    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        $models = $this->getModels();
        $rate = $models[$model]['cost_per_1k'] ?? 0.003;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

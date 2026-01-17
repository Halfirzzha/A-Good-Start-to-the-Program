<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * Cohere Provider (Command R+, Command R, Embed)
 *
 * Enterprise-focused AI with excellent retrieval-augmented generation (RAG)
 * and embedding capabilities.
 *
 * Best for: RAG applications, semantic search, enterprise workloads.
 *
 * API Documentation: https://docs.cohere.com/reference/
 *
 * @author Enterprise AI Architecture
 * @since 1.2.6
 */
class CohereProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 14; // Lower priority - specialized use
    }

    public function getIdentifier(): string
    {
        return 'cohere';
    }

    public function getName(): string
    {
        return 'Cohere';
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
        return 'https://api.cohere.ai/v1/chat';
    }

    public function getModels(): array
    {
        return [
            'command-r-plus' => [
                'name' => 'Command R+',
                'cost_per_1k' => 0.003,
                'max_tokens' => 128000,
                'recommended_for' => 'Complex RAG, enterprise workloads',
            ],
            'command-r' => [
                'name' => 'Command R',
                'cost_per_1k' => 0.0005,
                'max_tokens' => 128000,
                'recommended_for' => 'Balanced RAG tasks, cost-effective',
            ],
            'command' => [
                'name' => 'Command',
                'cost_per_1k' => 0.0015,
                'max_tokens' => 4096,
                'recommended_for' => 'Simple generation tasks',
            ],
            'command-light' => [
                'name' => 'Command Light',
                'cost_per_1k' => 0.0003,
                'max_tokens' => 4096,
                'recommended_for' => 'Fast, lightweight tasks',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'command-r';
    }

    protected function buildHeaders(string $apiKey): array
    {
        return [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function buildPayload(string $prompt, string $model, array $options): array
    {
        $payload = [
            'model' => $model,
            'message' => $prompt,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? $this->temperature,
        ];

        if (isset($options['system'])) {
            $payload['preamble'] = $options['system'];
        }

        return $payload;
    }

    protected function parseResponse(array $response, float $latencyMs): AIResponse
    {
        $content = $response['text'] ?? null;

        if (! $content) {
            return AIResponse::failure(
                'No content in Cohere response',
                $this->getIdentifier(),
                'empty_response',
                $latencyMs
            );
        }

        $meta = $response['meta'] ?? [];
        $tokens = $meta['tokens'] ?? [];
        $inputTokens = $tokens['input_tokens'] ?? 0;
        $outputTokens = $tokens['output_tokens'] ?? 0;

        return AIResponse::success(
            content: $content,
            provider: $this->getIdentifier(),
            model: $response['model'] ?? $this->getDefaultModel(),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $this->estimateCost($inputTokens, $outputTokens),
            latencyMs: $latencyMs,
            metadata: [
                'generation_id' => $response['generation_id'] ?? null,
                'finish_reason' => $response['finish_reason'] ?? null,
            ],
        );
    }

    protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse
    {
        $message = $response['message'] ?? 'Unknown Cohere error';

        $code = match ($statusCode) {
            401 => 'invalid_api_key',
            429 => 'rate_limit_exceeded',
            500, 502, 503 => 'server_error',
            default => 'unknown',
        };

        return AIResponse::failure($message, $this->getIdentifier(), $code, $latencyMs);
    }

    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        $models = $this->getModels();
        $rate = $models[$model]['cost_per_1k'] ?? 0.0005;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

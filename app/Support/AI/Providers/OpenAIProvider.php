<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * OpenAI Provider (GPT-4o, GPT-4o-mini, GPT-3.5-turbo)
 *
 * Industry standard AI provider with excellent quality.
 * Best for: Complex reasoning, professional content, code generation.
 */
class OpenAIProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    protected ?string $organization = null;

    public function __construct(?string $apiKey = null, ?string $organization = null)
    {
        $this->apiKey = $apiKey;
        $this->organization = $organization;
        $this->priority = 10;
    }

    public function getIdentifier(): string
    {
        return 'openai';
    }

    public function getName(): string
    {
        return 'OpenAI';
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

    public function setOrganization(?string $organization): void
    {
        $this->organization = $organization;
    }

    protected function getApiEndpoint(): string
    {
        return 'https://api.openai.com/v1/chat/completions';
    }

    public function getModels(): array
    {
        return [
            'gpt-4o' => [
                'name' => 'GPT-4o',
                'cost_per_1k' => 0.005,
                'max_tokens' => 128000,
                'recommended_for' => 'Complex reasoning, professional content',
            ],
            'gpt-4o-mini' => [
                'name' => 'GPT-4o Mini',
                'cost_per_1k' => 0.00015,
                'max_tokens' => 128000,
                'recommended_for' => 'Fast, cost-effective tasks',
            ],
            'gpt-4-turbo' => [
                'name' => 'GPT-4 Turbo',
                'cost_per_1k' => 0.01,
                'max_tokens' => 128000,
                'recommended_for' => 'Complex analysis, long context',
            ],
            'gpt-3.5-turbo' => [
                'name' => 'GPT-3.5 Turbo',
                'cost_per_1k' => 0.0005,
                'max_tokens' => 16385,
                'recommended_for' => 'Simple tasks, high volume, budget',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'gpt-4o-mini'; // Best balance of quality and cost
    }

    protected function buildHeaders(string $apiKey): array
    {
        $headers = parent::buildHeaders($apiKey);

        if ($this->organization && preg_match('/^org-[a-zA-Z0-9]+$/', $this->organization)) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers;
    }

    protected function buildPayload(string $prompt, string $model, array $options): array
    {
        $systemPrompt = $options['system'] ?? 'You are a helpful assistant. Respond concisely.';

        return [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt],
            ],
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
        $message = $error['message'] ?? 'Unknown OpenAI error';
        $code = $error['code'] ?? null;

        // Map HTTP status to error codes
        if (! $code) {
            $code = match ($statusCode) {
                401 => 'invalid_api_key',
                429 => 'rate_limit_exceeded',
                500, 502, 503 => 'server_error',
                default => 'unknown',
            };
        }

        return AIResponse::failure($message, $this->getIdentifier(), $code, $latencyMs);
    }

    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        $models = $this->getModels();
        $rate = $models[$model]['cost_per_1k'] ?? 0.005;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

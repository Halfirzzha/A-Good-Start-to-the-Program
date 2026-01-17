<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * DeepSeek Provider (DeepSeek-V3, DeepSeek-R1, DeepSeek-Coder)
 *
 * DeepSeek offers state-of-the-art AI models with excellent reasoning
 * capabilities at very competitive pricing.
 *
 * Best for: Complex reasoning, code generation, cost-effective operations.
 *
 * API Documentation: https://platform.deepseek.com/api-docs
 *
 * @author Enterprise AI Architecture
 * @since 1.2.6
 */
class DeepSeekProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 6; // High priority - excellent quality/cost ratio
    }

    public function getIdentifier(): string
    {
        return 'deepseek';
    }

    public function getName(): string
    {
        return 'DeepSeek';
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
        return 'https://api.deepseek.com/chat/completions';
    }

    public function getModels(): array
    {
        return [
            'deepseek-chat' => [
                'name' => 'DeepSeek-V3',
                'cost_per_1k' => 0.00014,
                'max_tokens' => 65536,
                'recommended_for' => 'General chat, excellent quality/cost ratio',
            ],
            'deepseek-reasoner' => [
                'name' => 'DeepSeek-R1',
                'cost_per_1k' => 0.00055,
                'max_tokens' => 65536,
                'recommended_for' => 'Complex reasoning, math, logic problems',
            ],
            'deepseek-coder' => [
                'name' => 'DeepSeek Coder',
                'cost_per_1k' => 0.00014,
                'max_tokens' => 65536,
                'recommended_for' => 'Code generation and debugging',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'deepseek-chat';
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
                'No content in DeepSeek response',
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
                'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
            ],
        );
    }

    protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown DeepSeek error';
        $type = $error['type'] ?? null;

        $code = match ($type) {
            'invalid_api_key' => 'invalid_api_key',
            'rate_limit_exceeded' => 'rate_limit_exceeded',
            'insufficient_quota' => 'quota_exceeded',
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
        $rate = $models[$model]['cost_per_1k'] ?? 0.00014;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * Mistral AI Provider (Mistral Large, Mistral Medium, Codestral)
 *
 * European AI leader with excellent multilingual capabilities
 * and strong code generation models.
 *
 * Best for: Multilingual content, European compliance, code generation.
 *
 * API Documentation: https://docs.mistral.ai/api/
 *
 * @author Enterprise AI Architecture
 * @since 1.2.6
 */
class MistralProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 12; // Medium priority
    }

    public function getIdentifier(): string
    {
        return 'mistral';
    }

    public function getName(): string
    {
        return 'Mistral AI';
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
        return 'https://api.mistral.ai/v1/chat/completions';
    }

    public function getModels(): array
    {
        return [
            'mistral-large-latest' => [
                'name' => 'Mistral Large',
                'cost_per_1k' => 0.002,
                'max_tokens' => 131072,
                'recommended_for' => 'Complex tasks, reasoning, multilingual',
            ],
            'mistral-medium-latest' => [
                'name' => 'Mistral Medium',
                'cost_per_1k' => 0.00065,
                'max_tokens' => 32768,
                'recommended_for' => 'Balanced performance and cost',
            ],
            'mistral-small-latest' => [
                'name' => 'Mistral Small',
                'cost_per_1k' => 0.0002,
                'max_tokens' => 32768,
                'recommended_for' => 'Fast, cost-effective tasks',
            ],
            'codestral-latest' => [
                'name' => 'Codestral',
                'cost_per_1k' => 0.0003,
                'max_tokens' => 32768,
                'recommended_for' => 'Code generation and completion',
            ],
            'pixtral-large-latest' => [
                'name' => 'Pixtral Large',
                'cost_per_1k' => 0.002,
                'max_tokens' => 131072,
                'recommended_for' => 'Vision and multimodal tasks',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'mistral-small-latest';
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
                'No content in Mistral response',
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
        $error = $response['error'] ?? $response['message'] ?? [];
        $message = is_string($error) ? $error : ($error['message'] ?? 'Unknown Mistral error');
        $type = is_array($error) ? ($error['type'] ?? null) : null;

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
        $rate = $models[$model]['cost_per_1k'] ?? 0.0002;

        return (($inputTokens + $outputTokens) / 1000) * $rate;
    }
}

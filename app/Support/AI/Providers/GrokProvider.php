<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * xAI Grok Provider (Grok-2, Grok-2-mini, Grok-beta)
 *
 * Grok is xAI's flagship AI model with real-time knowledge and wit.
 * Best for: Current events analysis, witty responses, honest answers.
 *
 * API Documentation: https://docs.x.ai/api
 *
 * @author Enterprise AI Architecture
 * @since 1.2.6
 */
class GrokProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    /**
     * Create a new Grok provider instance.
     */
    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 8; // Between Groq (5) and OpenAI (10) - high priority due to advanced reasoning
    }

    /**
     * Get the unique identifier for this provider.
     */
    public function getIdentifier(): string
    {
        return 'grok';
    }

    /**
     * Get the display name for this provider.
     */
    public function getName(): string
    {
        return 'xAI Grok';
    }

    /**
     * Check if the provider is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->getApiKey());
    }

    /**
     * Get the API key.
     */
    protected function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Set the API key.
     */
    public function setApiKey(?string $apiKey): void
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get the xAI API endpoint.
     *
     * Uses OpenAI-compatible endpoint format.
     */
    protected function getApiEndpoint(): string
    {
        return 'https://api.x.ai/v1/chat/completions';
    }

    /**
     * Get available Grok models with their specifications.
     *
     * @return array<string, array{name: string, cost_per_1k: float, max_tokens: int, recommended_for: string}>
     */
    public function getModels(): array
    {
        return [
            'grok-3' => [
                'name' => 'Grok 3',
                'cost_per_1k' => 0.003,
                'max_tokens' => 131072,
                'recommended_for' => 'Latest & most capable model, advanced reasoning',
            ],
            'grok-3-fast' => [
                'name' => 'Grok 3 Fast',
                'cost_per_1k' => 0.0015,
                'max_tokens' => 131072,
                'recommended_for' => 'Fast responses with great quality',
            ],
            'grok-2-latest' => [
                'name' => 'Grok 2 Latest',
                'cost_per_1k' => 0.002,
                'max_tokens' => 131072,
                'recommended_for' => 'Balanced performance and cost',
            ],
            'grok-2-vision-latest' => [
                'name' => 'Grok 2 Vision',
                'cost_per_1k' => 0.002,
                'max_tokens' => 32768,
                'recommended_for' => 'Image understanding and analysis',
            ],
            'grok-vision-beta' => [
                'name' => 'Grok Vision Beta',
                'cost_per_1k' => 0.0015,
                'max_tokens' => 8192,
                'recommended_for' => 'Vision capabilities (beta)',
            ],
        ];
    }

    /**
     * Get the default model for Grok.
     */
    public function getDefaultModel(): string
    {
        return 'grok-3-fast'; // Best balance of speed and quality
    }

    /**
     * Build the request headers.
     *
     * @return array<string, string>
     */
    protected function buildHeaders(string $apiKey): array
    {
        return [
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Build the request payload for Grok API.
     *
     * Grok uses OpenAI-compatible format.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function buildPayload(string $prompt, string $model, array $options): array
    {
        $messages = [];

        // Add system message if provided
        if (isset($options['system'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $options['system'],
            ];
        }

        // Add user message
        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? $this->temperature,
        ];

        // Add optional parameters if specified
        if (isset($options['top_p'])) {
            $payload['top_p'] = $options['top_p'];
        }

        if (isset($options['frequency_penalty'])) {
            $payload['frequency_penalty'] = $options['frequency_penalty'];
        }

        if (isset($options['presence_penalty'])) {
            $payload['presence_penalty'] = $options['presence_penalty'];
        }

        // Stream support
        if (isset($options['stream']) && $options['stream'] === true) {
            $payload['stream'] = true;
        }

        return $payload;
    }

    /**
     * Parse successful API response.
     *
     * @param  array<string, mixed>  $response
     */
    protected function parseResponse(array $response, float $latencyMs): AIResponse
    {
        $content = $response['choices'][0]['message']['content'] ?? null;

        if (! $content) {
            return AIResponse::failure(
                'No content in Grok response',
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
                'object' => $response['object'] ?? null,
                'created' => $response['created'] ?? null,
                'system_fingerprint' => $response['system_fingerprint'] ?? null,
                'finish_reason' => $response['choices'][0]['finish_reason'] ?? null,
            ],
        );
    }

    /**
     * Parse error response from Grok API.
     *
     * @param  array<string, mixed>  $response
     */
    protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown Grok API error';
        $type = $error['type'] ?? null;
        $code = $error['code'] ?? null;

        // Map error codes
        $errorCode = match (true) {
            $statusCode === 401 => 'authentication_error',
            $statusCode === 403 => 'permission_denied',
            $statusCode === 429 => 'rate_limit_exceeded',
            $statusCode === 500 => 'server_error',
            $statusCode === 502, $statusCode === 503 => 'service_unavailable',
            $type === 'invalid_request_error' => 'invalid_request',
            $type === 'authentication_error' => 'authentication_error',
            $code === 'context_length_exceeded' => 'context_length_exceeded',
            default => 'unknown_error',
        };

        return AIResponse::failure(
            $message,
            $this->getIdentifier(),
            $errorCode,
            $latencyMs,
            [
                'http_status' => $statusCode,
                'error_type' => $type,
                'error_code' => $code,
            ],
        );
    }

    /**
     * Estimate cost based on token usage.
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float
    {
        $model = $model ?? $this->getDefaultModel();
        $models = $this->getModels();
        $costPer1k = $models[$model]['cost_per_1k'] ?? 0.002; // Default to grok-2-latest pricing

        // xAI Grok uses similar pricing model to OpenAI (input slightly cheaper than output)
        $inputCost = ($inputTokens / 1000) * $costPer1k;
        $outputCost = ($outputTokens / 1000) * ($costPer1k * 1.5); // Output typically 1.5x input cost

        return round($inputCost + $outputCost, 6);
    }

    /**
     * Get provider-specific configuration for display.
     *
     * @return array<string, mixed>
     */
    public function getProviderInfo(): array
    {
        return [
            'name' => $this->getName(),
            'identifier' => $this->getIdentifier(),
            'priority' => $this->priority,
            'models' => $this->getModels(),
            'default_model' => $this->getDefaultModel(),
            'features' => [
                'Real-time knowledge',
                'Advanced reasoning',
                'Witty responses',
                'Vision support (beta)',
                'OpenAI-compatible API',
            ],
            'documentation' => 'https://docs.x.ai/api',
            'console' => 'https://console.x.ai',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Support\AI\Providers;

use App\Support\AI\AbstractAIProvider;
use App\Support\AI\AIResponse;

/**
 * Google Gemini Provider (Gemini 2.0 Flash, Gemini 1.5 Pro)
 *
 * Google's AI models with excellent multimodal capabilities.
 * Best for: Fast responses, good quality, competitive pricing.
 */
class GeminiProvider extends AbstractAIProvider
{
    protected ?string $apiKey = null;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
        $this->priority = 30;
    }

    public function getIdentifier(): string
    {
        return 'gemini';
    }

    public function getName(): string
    {
        return 'Google Gemini';
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
        $model = $this->getDefaultModel();
        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    protected function getApiEndpointForModel(string $model): string
    {
        return "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    public function getModels(): array
    {
        return [
            'gemini-2.0-flash-exp' => [
                'name' => 'Gemini 2.0 Flash',
                'cost_per_1k' => 0.0001,
                'max_tokens' => 1048576,
                'recommended_for' => 'Fastest, most cost-effective',
            ],
            'gemini-1.5-flash' => [
                'name' => 'Gemini 1.5 Flash',
                'cost_per_1k' => 0.000075,
                'max_tokens' => 1048576,
                'recommended_for' => 'Fast, very cheap, good quality',
            ],
            'gemini-1.5-pro' => [
                'name' => 'Gemini 1.5 Pro',
                'cost_per_1k' => 0.00125,
                'max_tokens' => 2097152,
                'recommended_for' => 'Best quality, complex tasks',
            ],
        ];
    }

    public function getDefaultModel(): string
    {
        return 'gemini-1.5-flash'; // Very cheap and fast
    }

    protected function buildHeaders(string $apiKey): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    protected function sendRequest(string $prompt, string $model, array $options): AIResponse
    {
        $apiKey = $this->getApiKey();
        if (! $apiKey) {
            return AIResponse::failure(
                'API key not configured',
                $this->getIdentifier(),
                'no_api_key'
            );
        }

        $payload = $this->buildPayload($prompt, $model, $options);
        $endpoint = $this->getApiEndpointForModel($model) . '?key=' . $apiKey;

        $startTime = microtime(true);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = \Illuminate\Support\Facades\Http::timeout($this->timeout)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($endpoint, $payload);

        $latencyMs = (microtime(true) - $startTime) * 1000;

        if ($response->successful()) {
            return $this->parseResponse($response->json() ?? [], $latencyMs);
        }

        return $this->parseError(
            $response->json() ?? [],
            $response->status(),
            $latencyMs
        );
    }

    protected function buildPayload(string $prompt, string $model, array $options): array
    {
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? $this->maxTokens,
                'temperature' => $options['temperature'] ?? $this->temperature,
            ],
        ];

        if (isset($options['system'])) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $options['system']],
                ],
            ];
        }

        return $payload;
    }

    protected function parseResponse(array $response, float $latencyMs): AIResponse
    {
        $content = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (! $content) {
            return AIResponse::failure(
                'No content in response',
                $this->getIdentifier(),
                'empty_response',
                $latencyMs
            );
        }

        $usage = $response['usageMetadata'] ?? [];
        $inputTokens = $usage['promptTokenCount'] ?? 0;
        $outputTokens = $usage['candidatesTokenCount'] ?? 0;

        return AIResponse::success(
            content: $content,
            provider: $this->getIdentifier(),
            model: $response['modelVersion'] ?? $this->getDefaultModel(),
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cost: $this->estimateCost($inputTokens, $outputTokens),
            latencyMs: $latencyMs,
        );
    }

    protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse
    {
        $error = $response['error'] ?? [];
        $message = $error['message'] ?? 'Unknown Gemini error';
        $status = $error['status'] ?? null;

        $code = match ($status) {
            'INVALID_ARGUMENT' => 'invalid_request',
            'PERMISSION_DENIED' => 'invalid_api_key',
            'RESOURCE_EXHAUSTED' => 'rate_limit_exceeded',
            'UNAVAILABLE' => 'server_error',
            default => match ($statusCode) {
                401, 403 => 'invalid_api_key',
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

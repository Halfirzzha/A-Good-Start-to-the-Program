<?php

declare(strict_types=1);

namespace App\Support\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for AI providers.
 *
 * Provides common functionality for all AI providers including:
 * - Circuit breaker pattern for fault tolerance
 * - Rate limiting and backoff
 * - Usage tracking
 * - Health monitoring
 * - Smart model fallback (auto-switch to better models)
 */
abstract class AbstractAIProvider implements AIProviderInterface
{
    protected int $priority = 50;

    protected int $timeout = 30;

    protected int $retryAttempts = 2;

    protected float $temperature = 0.7;

    protected int $maxTokens = 512;

    // Circuit breaker settings
    protected int $circuitBreakerThreshold = 3; // Failures before opening circuit

    protected int $circuitBreakerTimeout = 300; // Seconds to wait before retry

    // Smart model fallback
    protected bool $modelFallbackEnabled = true;

    /**
     * Get the API key for this provider.
     */
    abstract protected function getApiKey(): ?string;

    /**
     * Get the API endpoint URL.
     */
    abstract protected function getApiEndpoint(): string;

    /**
     * Build the request payload.
     *
     * @param string $prompt The prompt
     * @param string $model The model to use
     * @param array $options Additional options
     * @return array The request payload
     */
    abstract protected function buildPayload(string $prompt, string $model, array $options): array;

    /**
     * Parse the API response.
     *
     * @param array $response The raw API response
     * @param float $latencyMs Request latency
     * @return AIResponse
     */
    abstract protected function parseResponse(array $response, float $latencyMs): AIResponse;

    /**
     * Parse an error response.
     *
     * @param array $response The error response
     * @param int $statusCode HTTP status code
     * @param float $latencyMs Request latency
     * @return AIResponse
     */
    abstract protected function parseError(array $response, int $statusCode, float $latencyMs): AIResponse;

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            Log::debug("AI Provider {$this->getIdentifier()} circuit is open");
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function complete(string $prompt, ?string $model = null, array $options = []): AIResponse
    {
        if (! $this->isConfigured()) {
            return AIResponse::failure(
                'Provider not configured',
                $this->getIdentifier(),
                'not_configured'
            );
        }

        if ($this->isCircuitOpen()) {
            return AIResponse::failure(
                'Provider temporarily unavailable (circuit open)',
                $this->getIdentifier(),
                'circuit_open'
            );
        }

        // Get models to try (with smart fallback)
        $modelsToTry = $this->getModelsToTry($model, $options);
        $lastResponse = null;

        foreach ($modelsToTry as $currentModel) {
            $attempt = 0;

            while ($attempt < $this->retryAttempts) {
                $attempt++;
                $startTime = microtime(true);

                try {
                    $response = $this->sendRequest($prompt, $currentModel, $options);
                    $latencyMs = (microtime(true) - $startTime) * 1000;

                    if ($response->success) {
                        $this->recordSuccess();
                        $this->recordModelSuccess($currentModel);
                        $this->trackUsage($response);

                        return $response;
                    }

                    $lastResponse = $response;

                    // Check if we should try a different model
                    if ($this->shouldFallbackToNextModel($response)) {
                        Log::debug("[{$this->getIdentifier()}] Model {$currentModel} failed, trying next model", [
                            'error_code' => $response->errorCode,
                        ]);
                        break; // Exit retry loop, try next model
                    }

                    // Don't retry on non-recoverable errors
                    if (! $response->isRecoverable()) {
                        $this->recordFailure();

                        return $response;
                    }

                    // Don't retry on rate limits, let orchestrator handle failover
                    if ($response->isRateLimited()) {
                        $this->recordRateLimit();

                        return $response;
                    }

                } catch (\Exception $e) {
                    $latencyMs = (microtime(true) - $startTime) * 1000;
                    $lastResponse = AIResponse::failure(
                        $e->getMessage(),
                        $this->getIdentifier(),
                        'exception',
                        $latencyMs
                    );

                    Log::warning("AI Provider {$this->getIdentifier()} exception", [
                        'model' => $currentModel,
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                    ]);
                }

                // Minimal backoff between retries
                if ($attempt < $this->retryAttempts) {
                    usleep(250000 * $attempt); // 250ms, 500ms, ...
                }
            }
        }

        $this->recordFailure();

        return $lastResponse ?? AIResponse::failure(
            'Unknown error after retries',
            $this->getIdentifier(),
            'unknown'
        );
    }

    /**
     * Get the list of models to try with smart fallback.
     *
     * @return array<string>
     */
    protected function getModelsToTry(?string $requestedModel, array $options): array
    {
        // If model fallback is disabled or specific model requested, use only that
        if (! $this->modelFallbackEnabled || isset($options['disable_model_fallback'])) {
            return [$requestedModel ?? $this->getDefaultModel()];
        }

        $models = array_keys($this->getModels());

        // If specific model requested, start with it then fallback to others
        if ($requestedModel && in_array($requestedModel, $models)) {
            $otherModels = array_filter($models, fn ($m) => $m !== $requestedModel);

            return array_merge([$requestedModel], $this->sortModelsByQuality($otherModels));
        }

        // Try last successful model first, then by quality
        $lastSuccessful = $this->getLastSuccessfulModel();
        if ($lastSuccessful && in_array($lastSuccessful, $models)) {
            $otherModels = array_filter($models, fn ($m) => $m !== $lastSuccessful);

            return array_merge([$lastSuccessful], $this->sortModelsByQuality($otherModels));
        }

        // Default: sort by quality (higher cost = higher quality typically)
        return $this->sortModelsByQuality($models);
    }

    /**
     * Sort models by quality (based on cost as proxy for capability).
     *
     * @param  array<string>  $models
     * @return array<string>
     */
    protected function sortModelsByQuality(array $models): array
    {
        $modelInfo = $this->getModels();

        usort($models, function ($a, $b) use ($modelInfo) {
            $costA = $modelInfo[$a]['cost_per_1k'] ?? 0;
            $costB = $modelInfo[$b]['cost_per_1k'] ?? 0;

            return $costB <=> $costA; // Higher cost first (better quality)
        });

        return $models;
    }

    /**
     * Check if we should fallback to the next model.
     */
    protected function shouldFallbackToNextModel(AIResponse $response): bool
    {
        if (! $this->modelFallbackEnabled) {
            return false;
        }

        // Fallback on these error codes
        $fallbackErrors = [
            'context_length_exceeded',
            'model_not_found',
            'model_unavailable',
            'invalid_model',
            'server_error',
            'empty_response',
        ];

        return in_array($response->errorCode, $fallbackErrors);
    }

    /**
     * Get the last successful model for this provider.
     */
    protected function getLastSuccessfulModel(): ?string
    {
        return Cache::get("ai_model_success:{$this->getIdentifier()}");
    }

    /**
     * Record a successful model.
     */
    protected function recordModelSuccess(string $model): void
    {
        Cache::put("ai_model_success:{$this->getIdentifier()}", $model, 3600); // 1 hour
    }

    /**
     * Enable or disable model fallback.
     */
    public function setModelFallback(bool $enabled): void
    {
        $this->modelFallbackEnabled = $enabled;
    }

    /**
     * Send the actual HTTP request.
     */
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
        $headers = $this->buildHeaders($apiKey);

        $startTime = microtime(true);

        /** @var \Illuminate\Http\Client\Response $response */
        $response = \Illuminate\Support\Facades\Http::timeout($this->timeout)
            ->withHeaders($headers)
            ->post($this->getApiEndpoint(), $payload);

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

    /**
     * Build request headers.
     */
    protected function buildHeaders(string $apiKey): array
    {
        return [
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Check if circuit breaker is open.
     */
    protected function isCircuitOpen(): bool
    {
        $key = "ai_circuit:{$this->getIdentifier()}";
        return Cache::get($key, false) === true;
    }

    /**
     * Open the circuit breaker.
     */
    protected function openCircuit(): void
    {
        $key = "ai_circuit:{$this->getIdentifier()}";
        Cache::put($key, true, $this->circuitBreakerTimeout);

        Log::warning("AI Provider {$this->getIdentifier()} circuit opened", [
            'timeout' => $this->circuitBreakerTimeout,
        ]);
    }

    /**
     * Record a successful request.
     */
    protected function recordSuccess(): void
    {
        $key = "ai_failures:{$this->getIdentifier()}";
        Cache::forget($key);
    }

    /**
     * Record a failed request.
     */
    protected function recordFailure(): void
    {
        $key = "ai_failures:{$this->getIdentifier()}";
        $failures = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $failures, 300);

        if ($failures >= $this->circuitBreakerThreshold) {
            $this->openCircuit();
        }
    }

    /**
     * Record a rate limit hit.
     */
    protected function recordRateLimit(): void
    {
        $key = "ai_ratelimit:{$this->getIdentifier()}";
        Cache::put($key, true, 60); // 1 minute cooldown
    }

    /**
     * Track usage for analytics.
     */
    protected function trackUsage(AIResponse $response): void
    {
        $today = now()->toDateString();
        $key = "ai_usage:{$this->getIdentifier()}:{$today}";

        $usage = Cache::get($key, ['tokens' => 0, 'cost' => 0, 'requests' => 0]);
        $usage['tokens'] += $response->totalTokens;
        $usage['cost'] += $response->cost;
        $usage['requests']++;

        Cache::put($key, $usage, now()->addDays(7));
    }

    /**
     * {@inheritdoc}
     */
    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Provider not configured - missing API key',
            ];
        }

        $startTime = microtime(true);

        try {
            $response = $this->complete('Hi', null, [
                'max_tokens' => 5,
                'temperature' => 0.1,
            ]);

            $latencyMs = (microtime(true) - $startTime) * 1000;

            if ($response->success) {
                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'latency_ms' => round($latencyMs, 2),
                    'model' => $response->model,
                    'tokens_used' => $response->totalTokens,
                ];
            }

            return [
                'success' => false,
                'message' => $response->error ?? 'Unknown error',
                'latency_ms' => round($latencyMs, 2),
                'error_code' => $response->errorCode,
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * {@inheritdoc}
     */
    public function setPriority(int $priority): void
    {
        $this->priority = $priority;
    }

    /**
     * Set timeout.
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * Set temperature.
     */
    public function setTemperature(float $temperature): void
    {
        $this->temperature = max(0, min(2, $temperature));
    }

    /**
     * Set max tokens.
     */
    public function setMaxTokens(int $maxTokens): void
    {
        $this->maxTokens = $maxTokens;
    }
}

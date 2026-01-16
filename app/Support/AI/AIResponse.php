<?php

declare(strict_types=1);

namespace App\Support\AI;

/**
 * Standardized response from AI providers.
 *
 * This class encapsulates the response from any AI provider,
 * providing a consistent interface regardless of the underlying service.
 */
class AIResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $content = null,
        public readonly ?string $error = null,
        public readonly ?string $errorCode = null,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $totalTokens = 0,
        public readonly float $cost = 0.0,
        public readonly float $latencyMs = 0.0,
        public readonly string $model = '',
        public readonly string $provider = '',
        public readonly array $metadata = [],
    ) {}

    /**
     * Create a successful response.
     */
    public static function success(
        string $content,
        string $provider,
        string $model,
        int $inputTokens = 0,
        int $outputTokens = 0,
        float $cost = 0.0,
        float $latencyMs = 0.0,
        array $metadata = [],
    ): self {
        return new self(
            success: true,
            content: $content,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            totalTokens: $inputTokens + $outputTokens,
            cost: $cost,
            latencyMs: $latencyMs,
            model: $model,
            provider: $provider,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed response.
     */
    public static function failure(
        string $error,
        string $provider,
        ?string $errorCode = null,
        float $latencyMs = 0.0,
        array $metadata = [],
    ): self {
        return new self(
            success: false,
            error: $error,
            errorCode: $errorCode,
            latencyMs: $latencyMs,
            provider: $provider,
            metadata: $metadata,
        );
    }

    /**
     * Check if the error is recoverable (should try next provider).
     */
    public function isRecoverable(): bool
    {
        $nonRecoverable = [
            'insufficient_quota',
            'invalid_api_key',
            'account_suspended',
            'billing_hard_limit_reached',
        ];

        return ! in_array($this->errorCode, $nonRecoverable, true);
    }

    /**
     * Check if this is a rate limit error.
     */
    public function isRateLimited(): bool
    {
        return $this->errorCode === 'rate_limit_exceeded'
            || str_contains($this->error ?? '', 'rate limit');
    }

    /**
     * Check if this is a quota exceeded error.
     */
    public function isQuotaExceeded(): bool
    {
        return in_array($this->errorCode, [
            'insufficient_quota',
            'billing_hard_limit_reached',
            'quota_exceeded',
        ], true);
    }

    /**
     * Convert to array for logging/storage.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'content' => $this->content,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'cost' => $this->cost,
            'latency_ms' => $this->latencyMs,
            'model' => $this->model,
            'provider' => $this->provider,
            'metadata' => $this->metadata,
        ];
    }
}

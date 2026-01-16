<?php

declare(strict_types=1);

namespace App\Support\AI;

/**
 * Interface for AI Provider implementations.
 *
 * All AI providers must implement this interface to ensure
 * consistent behavior across different AI services.
 */
interface AIProviderInterface
{
    /**
     * Get the unique identifier for this provider.
     */
    public function getIdentifier(): string;

    /**
     * Get the display name for this provider.
     */
    public function getName(): string;

    /**
     * Check if this provider is properly configured.
     */
    public function isConfigured(): bool;

    /**
     * Check if this provider is currently available (not rate limited, has quota, etc.)
     */
    public function isAvailable(): bool;

    /**
     * Get the list of available models for this provider.
     *
     * @return array<string, array{name: string, cost_per_1k: float, max_tokens: int, recommended_for: string}>
     */
    public function getModels(): array;

    /**
     * Get the default model for this provider.
     */
    public function getDefaultModel(): string;

    /**
     * Send a completion request to the AI provider.
     *
     * @param string $prompt The prompt to send
     * @param string|null $model The model to use (null = default)
     * @param array{max_tokens?: int, temperature?: float, system?: string} $options
     * @return AIResponse
     */
    public function complete(string $prompt, ?string $model = null, array $options = []): AIResponse;

    /**
     * Test the connection to this provider.
     *
     * @return array{success: bool, message: string, latency_ms?: float}
     */
    public function testConnection(): array;

    /**
     * Get the estimated cost for a request.
     *
     * @param int $inputTokens Estimated input tokens
     * @param int $outputTokens Estimated output tokens
     * @param string|null $model The model to use
     */
    public function estimateCost(int $inputTokens, int $outputTokens, ?string $model = null): float;

    /**
     * Get the priority of this provider (lower = higher priority).
     */
    public function getPriority(): int;

    /**
     * Set the priority of this provider.
     */
    public function setPriority(int $priority): void;
}

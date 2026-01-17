<?php

declare(strict_types=1);

namespace App\Support\AI;

use App\Models\SystemSetting;
use App\Support\AI\Providers\AnthropicProvider;
use App\Support\AI\Providers\CohereProvider;
use App\Support\AI\Providers\DeepSeekProvider;
use App\Support\AI\Providers\GeminiProvider;
use App\Support\AI\Providers\GrokProvider;
use App\Support\AI\Providers\GroqProvider;
use App\Support\AI\Providers\MistralProvider;
use App\Support\AI\Providers\OpenAIProvider;
use App\Support\AI\Providers\OpenRouterProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI Orchestrator - Enterprise Multi-Provider AI Management
 *
 * This class manages multiple AI providers with:
 * - Automatic failover when a provider fails
 * - Smart provider selection based on priority and health
 * - Circuit breaker pattern for fault tolerance
 * - Cost tracking and optimization
 * - Comprehensive logging and analytics
 *
 * @author Enterprise AI Architecture
 */
class AIOrchestrator
{
    /**
     * Registered providers.
     *
     * @var array<string, AIProviderInterface>
     */
    protected array $providers = [];

    /**
     * Provider health status cache key.
     */
    protected const HEALTH_CACHE_KEY = 'ai:provider:health';

    /**
     * Last successful provider cache key.
     */
    protected const LAST_SUCCESS_CACHE_KEY = 'ai:provider:last_success';

    /**
     * Daily usage tracking cache key prefix.
     */
    protected const DAILY_USAGE_PREFIX = 'ai:daily_usage:';

    /**
     * Maximum providers to try before giving up.
     */
    protected int $maxRetries = 5;

    /**
     * Whether to use smart provider selection.
     */
    protected bool $smartSelection = true;

    /**
     * Whether failover is enabled.
     */
    protected bool $failoverEnabled = true;

    /**
     * Daily cost limit in USD.
     */
    protected float $dailyCostLimit = 10.0;

    /**
     * Create new orchestrator with providers from settings.
     */
    public function __construct()
    {
        $this->loadSettings();
        $this->registerDefaultProviders();
    }

    /**
     * Load settings from database.
     */
    protected function loadSettings(): void
    {
        $settings = Cache::remember('ai_orchestrator_settings', 300, function () {
            return SystemSetting::query()->first();
        });

        if ($settings) {
            $this->dailyCostLimit = (float) ($settings->ai_daily_limit ?? 10.0);
            $this->failoverEnabled = (bool) ($settings->ai_failover_enabled ?? true);
            $this->smartSelection = (bool) ($settings->ai_smart_selection ?? true);
        }
    }

    /**
     * Register all default providers from settings.
     */
    protected function registerDefaultProviders(): void
    {
        $settings = Cache::remember('ai_orchestrator_settings', 300, function () {
            return SystemSetting::query()->first();
        });

        if (! $settings) {
            return;
        }

        // Helper to safely get encrypted API key
        $getApiKey = function (string $attribute) use ($settings): ?string {
            try {
                $value = $settings->getAttribute($attribute);
                return ! empty($value) ? $value : null;
            } catch (Throwable $e) {
                Log::debug("[AIOrchestrator] Failed to decrypt {$attribute}: " . $e->getMessage());
                return null;
            }
        };

        // OpenAI
        if ($apiKey = $getApiKey('openai_api_key')) {
            $provider = new OpenAIProvider($apiKey);
            $this->registerProvider($provider);
        }

        // Anthropic (Claude)
        if ($apiKey = $getApiKey('anthropic_api_key')) {
            $provider = new AnthropicProvider($apiKey);
            $this->registerProvider($provider);
        }

        // Google Gemini (check both new and legacy field names)
        $geminiKey = $getApiKey('gemini_api_key') ?? $getApiKey('google_ai_api_key');
        if ($geminiKey) {
            $provider = new GeminiProvider($geminiKey);
            $this->registerProvider($provider);
        }

        // Groq (highest priority - fastest and cheapest)
        if ($apiKey = $getApiKey('groq_api_key')) {
            $provider = new GroqProvider($apiKey);
            $this->registerProvider($provider);
        }

        // xAI Grok (high priority - advanced reasoning and real-time knowledge)
        if ($apiKey = $getApiKey('xai_grok_api_key')) {
            $provider = new GrokProvider($apiKey);
            $this->registerProvider($provider);
        }

        // DeepSeek (excellent reasoning, very cost-effective)
        if ($apiKey = $getApiKey('deepseek_api_key')) {
            $provider = new DeepSeekProvider($apiKey);
            $this->registerProvider($provider);
        }

        // Mistral AI (European, multilingual, code)
        if ($apiKey = $getApiKey('mistral_api_key')) {
            $provider = new MistralProvider($apiKey);
            $this->registerProvider($provider);
        }

        // Cohere (RAG, embeddings, enterprise)
        if ($apiKey = $getApiKey('cohere_api_key')) {
            $provider = new CohereProvider($apiKey);
            $this->registerProvider($provider);
        }

        // OpenRouter (access to 100+ models including FREE ones)
        if ($apiKey = $getApiKey('openrouter_api_key')) {
            $provider = new OpenRouterProvider($apiKey);
            $provider->setSiteInfo(config('app.url'), config('app.name'));
            $this->registerProvider($provider);
        }
    }

    /**
     * Register a provider.
     */
    public function registerProvider(AIProviderInterface $provider): self
    {
        if ($provider->isConfigured()) {
            $this->providers[$provider->getIdentifier()] = $provider;
        }

        return $this;
    }

    /**
     * Get all registered providers.
     *
     * @return array<string, AIProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * Get a specific provider.
     */
    public function getProvider(string $identifier): ?AIProviderInterface
    {
        return $this->providers[$identifier] ?? null;
    }

    /**
     * Check if any provider is available.
     */
    public function hasAvailableProvider(): bool
    {
        foreach ($this->getProvidersInPriorityOrder() as $provider) {
            if ($provider->isAvailable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get providers sorted by priority (lower = higher priority).
     *
     * @return array<AIProviderInterface>
     */
    public function getProvidersInPriorityOrder(): array
    {
        $providers = array_values($this->providers);

        usort($providers, function (AIProviderInterface $a, AIProviderInterface $b) {
            return $a->getPriority() <=> $b->getPriority();
        });

        return $providers;
    }

    /**
     * Get the best available provider.
     */
    public function getBestProvider(): ?AIProviderInterface
    {
        // First, try the last successful provider
        if ($this->smartSelection) {
            $lastSuccess = $this->getLastSuccessfulProvider();
            if ($lastSuccess && isset($this->providers[$lastSuccess]) && $this->providers[$lastSuccess]->isAvailable()) {
                return $this->providers[$lastSuccess];
            }
        }

        // Fall back to priority order
        foreach ($this->getProvidersInPriorityOrder() as $provider) {
            if ($provider->isAvailable()) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Analyze prompt complexity and suggest appropriate model tier.
     *
     * @return string 'basic'|'standard'|'advanced'|'premium'
     */
    public function analyzePromptComplexity(string $prompt): string
    {
        $length = strlen($prompt);
        $wordCount = str_word_count($prompt);

        // Check for complexity indicators
        $complexityIndicators = [
            'analyze', 'explain', 'compare', 'evaluate', 'synthesize',
            'code', 'programming', 'algorithm', 'function', 'class',
            'security', 'vulnerability', 'threat', 'audit', 'compliance',
            'mathematical', 'calculate', 'formula', 'equation',
            'translate', 'multilingual', 'language',
            'creative', 'story', 'narrative', 'poem',
        ];

        $simpleIndicators = [
            'summarize', 'list', 'define', 'what is', 'simple',
            'short', 'brief', 'quick', 'basic',
        ];

        $promptLower = strtolower($prompt);
        $complexityScore = 0;

        // Length-based scoring
        if ($length > 2000) {
            $complexityScore += 3;
        } elseif ($length > 1000) {
            $complexityScore += 2;
        } elseif ($length > 500) {
            $complexityScore += 1;
        }

        // Indicator-based scoring
        foreach ($complexityIndicators as $indicator) {
            if (str_contains($promptLower, $indicator)) {
                $complexityScore += 2;
            }
        }

        foreach ($simpleIndicators as $indicator) {
            if (str_contains($promptLower, $indicator)) {
                $complexityScore -= 1;
            }
        }

        // Determine tier
        return match (true) {
            $complexityScore >= 8 => 'premium',
            $complexityScore >= 5 => 'advanced',
            $complexityScore >= 2 => 'standard',
            default => 'basic',
        };
    }

    /**
     * Get the recommended model for a provider based on task complexity.
     */
    public function getRecommendedModel(AIProviderInterface $provider, string $complexity = 'standard'): string
    {
        $models = $provider->getModels();
        $modelKeys = array_keys($models);

        if (empty($modelKeys)) {
            return $provider->getDefaultModel();
        }

        // Sort by cost (proxy for capability)
        usort($modelKeys, function ($a, $b) use ($models) {
            $costA = $models[$a]['cost_per_1k'] ?? 0;
            $costB = $models[$b]['cost_per_1k'] ?? 0;

            return $costB <=> $costA; // Higher cost first
        });

        // Select model based on complexity tier
        $modelCount = count($modelKeys);

        return match ($complexity) {
            'premium' => $modelKeys[0], // Best model
            'advanced' => $modelKeys[min(1, $modelCount - 1)], // Second best or best
            'standard' => $modelKeys[(int) floor($modelCount / 2)], // Middle
            'basic' => $modelKeys[$modelCount - 1], // Cheapest
            default => $provider->getDefaultModel(),
        };
    }

    /**
     * Complete with smart model selection based on prompt complexity.
     */
    public function completeWithSmartSelection(string $prompt, array $options = []): AIResponse
    {
        // Analyze prompt complexity if not already specified
        if (! isset($options['model']) && ! isset($options['complexity'])) {
            $complexity = $this->analyzePromptComplexity($prompt);
            $options['_complexity'] = $complexity;

            Log::debug('[AI Orchestrator] Smart model selection', [
                'complexity' => $complexity,
                'prompt_length' => strlen($prompt),
            ]);
        }

        return $this->complete($prompt, $options);
    }

    /**
     * Complete an AI request with automatic failover.
     */
    public function complete(string $prompt, array $options = []): AIResponse
    {
        // Check daily cost limit
        if ($this->isOverDailyLimit()) {
            return AIResponse::failure(
                'Daily AI cost limit exceeded. Please try again tomorrow.',
                'orchestrator',
                'daily_limit_exceeded',
            );
        }

        // Check if any provider is available
        if (empty($this->providers)) {
            return AIResponse::failure(
                'No AI providers are configured. Please configure at least one provider in System Settings.',
                'orchestrator',
                'no_providers',
            );
        }

        // Try providers in priority order with failover
        $providersToTry = $this->getProvidersInPriorityOrder();
        $attempts = 0;
        $lastError = null;
        $failedProviders = [];

        foreach ($providersToTry as $provider) {
            $attempts++;

            // Skip if provider is not available (circuit open, rate limited, etc.)
            if (! $provider->isAvailable()) {
                $failedProviders[] = $provider->getIdentifier() . ' (unavailable)';

                continue;
            }

            try {
                Log::debug('[AI Orchestrator] Trying provider', [
                    'provider' => $provider->getIdentifier(),
                    'attempt' => $attempts,
                    'priority' => $provider->getPriority(),
                ]);

                // Extract model from options, default to provider's default model
                $model = $options['model'] ?? null;
                $response = $provider->complete($prompt, $model, $options);

                if ($response->success) {
                    // Track successful provider
                    $this->setLastSuccessfulProvider($provider->getIdentifier());
                    $this->trackUsage($response);

                    Log::info('[AI Orchestrator] Success', [
                        'provider' => $provider->getIdentifier(),
                        'model' => $response->model,
                        'cost' => $response->cost,
                        'latency_ms' => $response->latencyMs,
                        'attempts' => $attempts,
                    ]);

                    return $response;
                }

                // Check if error is recoverable
                if ($response->isRecoverable() && $this->failoverEnabled && $attempts < $this->maxRetries) {
                    $failedProviders[] = $provider->getIdentifier() . ' (' . $response->errorCode . ')';
                    $lastError = $response;

                    continue;
                }

                // Non-recoverable error, return immediately
                $lastError = $response;

                if (! $this->failoverEnabled) {
                    break;
                }

            } catch (Throwable $e) {
                Log::error('[AI Orchestrator] Provider exception', [
                    'provider' => $provider->getIdentifier(),
                    'error' => $e->getMessage(),
                ]);

                $failedProviders[] = $provider->getIdentifier() . ' (exception)';
                $lastError = AIResponse::failure(
                    $e->getMessage(),
                    $provider->getIdentifier(),
                    'exception',
                );

                if (! $this->failoverEnabled) {
                    break;
                }
            }
        }

        // All providers failed
        $errorMessage = 'All AI providers failed. ';
        if ($failedProviders) {
            $errorMessage .= 'Tried: ' . implode(', ', $failedProviders) . '. ';
        }
        if ($lastError) {
            $errorMessage .= 'Last error: ' . $lastError->error;
        }

        Log::warning('[AI Orchestrator] All providers failed', [
            'failed_providers' => $failedProviders,
            'attempts' => $attempts,
        ]);

        return AIResponse::failure(
            $errorMessage,
            'orchestrator',
            'all_providers_failed',
        );
    }

    /**
     * Complete with a specific provider.
     */
    public function completeWith(string $providerIdentifier, string $prompt, array $options = []): AIResponse
    {
        $provider = $this->getProvider($providerIdentifier);

        if (! $provider) {
            return AIResponse::failure(
                "Provider '{$providerIdentifier}' not found or not configured.",
                'orchestrator',
                'provider_not_found',
            );
        }

        if (! $provider->isAvailable()) {
            return AIResponse::failure(
                "Provider '{$providerIdentifier}' is currently unavailable.",
                'orchestrator',
                'provider_unavailable',
            );
        }

        $model = $options['model'] ?? null;
        return $provider->complete($prompt, $model, $options);
    }

    /**
     * Test all providers and return health status.
     *
     * @return array<string, array>
     */
    public function testAllProviders(): array
    {
        $results = [];

        foreach ($this->providers as $identifier => $provider) {
            $result = $provider->testConnection();
            $results[$identifier] = [
                'name' => $provider->getName(),
                'identifier' => $identifier,
                'priority' => $provider->getPriority(),
                'configured' => $provider->isConfigured(),
                'available' => $provider->isAvailable(),
                'healthy' => $result['success'] ?? false,
                'error' => $result['error'] ?? $result['message'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'model' => $result['model'] ?? null,
            ];
        }

        // Cache health status for 5 minutes
        Cache::put(self::HEALTH_CACHE_KEY, $results, now()->addMinutes(5));

        return $results;
    }

    /**
     * Get cached health status.
     *
     * @return array<string, array>|null
     */
    public function getCachedHealth(): ?array
    {
        return Cache::get(self::HEALTH_CACHE_KEY);
    }

    /**
     * Get the last successful provider identifier.
     */
    protected function getLastSuccessfulProvider(): ?string
    {
        return Cache::get(self::LAST_SUCCESS_CACHE_KEY);
    }

    /**
     * Set the last successful provider.
     */
    protected function setLastSuccessfulProvider(string $identifier): void
    {
        Cache::put(self::LAST_SUCCESS_CACHE_KEY, $identifier, now()->addHours(1));
    }

    /**
     * Track usage for daily limits and analytics.
     */
    protected function trackUsage(AIResponse $response): void
    {
        $key = self::DAILY_USAGE_PREFIX . now()->format('Y-m-d');
        $usage = Cache::get($key, ['cost' => 0, 'requests' => 0, 'tokens' => 0, 'providers' => []]);

        $usage['cost'] += $response->cost;
        $usage['requests']++;
        $usage['tokens'] += $response->inputTokens + $response->outputTokens;

        if (! isset($usage['providers'][$response->provider])) {
            $usage['providers'][$response->provider] = ['cost' => 0, 'requests' => 0];
        }
        $usage['providers'][$response->provider]['cost'] += $response->cost;
        $usage['providers'][$response->provider]['requests']++;

        Cache::put($key, $usage, now()->addDays(7));
    }

    /**
     * Get today's usage statistics.
     *
     * @return array
     */
    public function getTodayUsage(): array
    {
        $key = self::DAILY_USAGE_PREFIX . now()->format('Y-m-d');

        return Cache::get($key, [
            'cost' => 0,
            'requests' => 0,
            'tokens' => 0,
            'providers' => [],
        ]);
    }

    /**
     * Check if over daily cost limit.
     */
    public function isOverDailyLimit(): bool
    {
        $usage = $this->getTodayUsage();

        return $usage['cost'] >= $this->dailyCostLimit;
    }

    /**
     * Get remaining daily budget.
     */
    public function getRemainingBudget(): float
    {
        $usage = $this->getTodayUsage();

        return max(0, $this->dailyCostLimit - $usage['cost']);
    }

    /**
     * Get daily cost limit.
     */
    public function getDailyCostLimit(): float
    {
        return $this->dailyCostLimit;
    }

    /**
     * Set daily cost limit.
     */
    public function setDailyCostLimit(float $limit): self
    {
        $this->dailyCostLimit = $limit;

        return $this;
    }

    /**
     * Enable or disable failover.
     */
    public function setFailoverEnabled(bool $enabled): self
    {
        $this->failoverEnabled = $enabled;

        return $this;
    }

    /**
     * Enable or disable smart selection.
     */
    public function setSmartSelection(bool $enabled): self
    {
        $this->smartSelection = $enabled;

        return $this;
    }

    /**
     * Clear provider health cache.
     */
    public function clearHealthCache(): void
    {
        Cache::forget(self::HEALTH_CACHE_KEY);
        Cache::forget(self::LAST_SUCCESS_CACHE_KEY);
    }

    /**
     * Get summary for display.
     */
    public function getSummary(): array
    {
        $providers = $this->getProvidersInPriorityOrder();
        $usage = $this->getTodayUsage();

        return [
            'providers_configured' => count($this->providers),
            'providers_available' => count(array_filter($providers, fn ($p) => $p->isAvailable())),
            'best_provider' => $this->getBestProvider()?->getIdentifier(),
            'failover_enabled' => $this->failoverEnabled,
            'smart_selection' => $this->smartSelection,
            'daily_cost_limit' => $this->dailyCostLimit,
            'today_cost' => $usage['cost'],
            'today_requests' => $usage['requests'],
            'remaining_budget' => $this->getRemainingBudget(),
            'providers' => array_map(fn ($p) => [
                'identifier' => $p->getIdentifier(),
                'name' => $p->getName(),
                'priority' => $p->getPriority(),
                'available' => $p->isAvailable(),
            ], $providers),
        ];
    }
}

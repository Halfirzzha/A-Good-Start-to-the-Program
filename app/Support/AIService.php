<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use App\Support\AI\AIOrchestrator;
use App\Support\AI\AIResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Enterprise AI Service - Multi-Provider Content Generation
 *
 * Features:
 * - Automatic failover between providers (OpenAI, Anthropic, Gemini, Groq, OpenRouter)
 * - Response caching (24 hours) to minimize API costs
 * - Smart provider selection based on availability and success history
 * - Circuit breaker pattern for fault tolerance
 * - Daily cost limits and usage tracking
 * - Comprehensive error handling and logging
 *
 * @see AIOrchestrator For the multi-provider management engine
 */
class AIService
{
    protected ?SystemSetting $settings = null;

    protected ?AIOrchestrator $orchestrator = null;

    protected const CACHE_TTL_HOURS = 24;

    /**
     * Create a new AI Service instance.
     */
    public function __construct(?AIOrchestrator $orchestrator = null)
    {
        $this->loadSettings();
        $this->orchestrator = $orchestrator ?? new AIOrchestrator;
    }

    /**
     * Load AI settings from database.
     */
    protected function loadSettings(): void
    {
        $this->settings = Cache::remember('ai_service_settings', 300, function () {
            return SystemSetting::query()->first();
        });
    }

    /**
     * Check if AI is enabled and has at least one provider configured.
     */
    public function isEnabled(): bool
    {
        if (! $this->settings) {
            return false;
        }

        // Check legacy ai_enabled flag
        if (! (bool) ($this->settings->ai_enabled ?? true)) {
            return false;
        }

        // Check if any provider is available
        return $this->orchestrator->hasAvailableProvider();
    }

    /**
     * Get the orchestrator instance.
     */
    public function getOrchestrator(): AIOrchestrator
    {
        return $this->orchestrator;
    }

    /**
     * Generate cache key for maintenance content.
     */
    protected function getCacheKey(string $type, string $mode, string $language): string
    {
        return "ai_content:{$type}:{$mode}:{$language}";
    }

    /**
     * Get cached content if available.
     *
     * @return array{title: string, summary: string, note_html: string}|null
     */
    protected function getCachedContent(string $mode, string $language): ?array
    {
        $cacheKey = $this->getCacheKey('maintenance', $mode, $language);
        $cached = Cache::get($cacheKey);

        if ($cached && is_array($cached)) {
            Log::debug('[AIService] Using cached content', [
                'mode' => $mode,
                'language' => $language,
            ]);

            return $cached;
        }

        return null;
    }

    /**
     * Cache the generated content.
     */
    protected function cacheContent(string $mode, string $language, array $content): void
    {
        $cacheKey = $this->getCacheKey('maintenance', $mode, $language);
        Cache::put($cacheKey, $content, now()->addHours(self::CACHE_TTL_HOURS));
    }

    /**
     * Generate maintenance content using AI with multi-provider failover.
     *
     * @param  string  $mode  Access mode (global, allowlist, denylist)
     * @param  string  $context  Additional context for generation
     * @param  string  $language  Target language (en, id)
     * @return array{title: string, summary: string, note_html: string}|null
     */
    public function generateMaintenanceContent(
        string $mode,
        string $context = '',
        string $language = 'en'
    ): ?array {
        if (! $this->isEnabled()) {
            Log::debug('[AIService] AI is not enabled or no providers available');

            return null;
        }

        // Step 1: Check cache first (FREE)
        $cached = $this->getCachedContent($mode, $language);
        if ($cached) {
            return $cached;
        }

        // Step 2: Check daily cost limit
        if ($this->orchestrator->isOverDailyLimit()) {
            Log::info('[AIService] Daily cost limit reached');

            return null;
        }

        // Step 3: Build the prompt
        $prompt = $this->buildMaintenancePrompt($mode, $context, $language);
        $systemPrompt = $this->getSystemPrompt($language);

        try {
            // Step 4: Call orchestrator with automatic failover
            $response = $this->orchestrator->complete($prompt, [
                'system' => $systemPrompt,
                'max_tokens' => 512,
                'temperature' => 0.7,
            ]);

            if ($response->success) {
                $content = $this->parseMaintenanceResponse($response->content, $language);

                if ($content) {
                    // Cache successful response
                    $this->cacheContent($mode, $language, $content);

                    Log::info('[AIService] Content generated successfully', [
                        'provider' => $response->provider,
                        'model' => $response->model,
                        'cost' => '$' . number_format($response->cost, 6),
                        'latency_ms' => $response->latencyMs,
                    ]);

                    return $content;
                }

                Log::warning('[AIService] Failed to parse AI response', [
                    'provider' => $response->provider,
                ]);
            } else {
                Log::error('[AIService] AI generation failed', [
                    'error' => $response->error,
                    'error_code' => $response->errorCode,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[AIService] Exception during generation', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get the system prompt for the AI.
     */
    protected function getSystemPrompt(string $language): string
    {
        $lang = $language === 'id' ? 'Indonesian' : 'English';

        return <<<PROMPT
You are a professional technical writer. Generate responses in {$lang}.
Your responses must be valid JSON only - no markdown, no code blocks, no explanations.
Be professional, specific, and avoid generic phrases.
PROMPT;
    }

    /**
     * Build the maintenance prompt.
     */
    protected function buildMaintenancePrompt(string $mode, string $context, string $language): string
    {
        $lang = $language === 'id' ? 'Indonesian' : 'English';

        $modeDescription = match ($mode) {
            'allowlist' => 'System is in ALLOWLIST mode. Only whitelisted IPs/users can access. Others see maintenance page.',
            'denylist' => 'System is in DENYLIST mode. Specific IPs/users are blocked, all others have normal access.',
            default => 'System is in GLOBAL maintenance mode. All users see the maintenance page. Full lockdown.',
        };

        $contextInfo = $context ? "Additional context: {$context}" : '';

        return <<<PROMPT
Generate a professional maintenance page message for a web application.

Mode: {$mode}
Description: {$modeDescription}
Language: {$lang}
{$contextInfo}

Return ONLY valid JSON with this exact structure:
{
    "title": "Maximum 80 characters, professional title for the maintenance page",
    "summary": "Maximum 200 characters, brief user-friendly message explaining the situation",
    "note_html": "<p>A paragraph explaining details</p><ul><li>Point 1</li><li>Point 2</li><li>Point 3</li></ul>"
}

Requirements:
- Be specific to the mode (don't say "we'll be back soon" for allowlist mode)
- Use professional, reassuring tone
- Include practical information relevant to the mode
- HTML must be valid and use only: p, ul, ol, li, strong, em tags
PROMPT;
    }

    /**
     * Parse the AI response into structured maintenance content.
     *
     * @return array{title: string, summary: string, note_html: string}|null
     */
    protected function parseMaintenanceResponse(string $response, string $language): ?array
    {
        // Clean up response - remove any markdown artifacts
        $response = preg_replace('/```json\s*/', '', $response) ?? $response;
        $response = preg_replace('/```\s*/', '', $response) ?? $response;
        $response = preg_replace('/^\s*\{/', '{', $response) ?? $response;
        $response = preg_replace('/\}\s*$/', '}', $response) ?? $response;
        $response = trim($response);

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                return null;
            }

            $title = $data['title'] ?? '';
            $summary = $data['summary'] ?? '';
            $noteHtml = $data['note_html'] ?? $data['noteHtml'] ?? $data['note'] ?? '';

            if (empty($title) || empty($summary)) {
                Log::warning('[AIService] Response missing required fields', [
                    'has_title' => ! empty($title),
                    'has_summary' => ! empty($summary),
                ]);

                return null;
            }

            // Sanitize HTML
            $noteHtml = strip_tags($noteHtml, '<p><ul><ol><li><strong><em><br>');

            return [
                'title' => mb_substr(strip_tags($title), 0, 160),
                'summary' => mb_substr(strip_tags($summary), 0, 800),
                'note_html' => $noteHtml,
            ];
        } catch (\JsonException $e) {
            Log::warning('[AIService] Failed to parse JSON response', [
                'error' => $e->getMessage(),
                'response_preview' => mb_substr($response, 0, 200),
            ]);

            return null;
        }
    }

    /**
     * Generate notification content using AI.
     *
     * @param  string  $category  Notification category (maintenance, announcement, update, security)
     * @param  string  $priority  Priority level (normal, high, critical)
     * @param  string  $context  Additional context for generation
     * @param  string  $language  Target language (en, id)
     * @return array{title: string, message: string}|null
     */
    public function generateNotificationContent(
        string $category,
        string $priority = 'normal',
        string $context = '',
        string $language = 'en'
    ): ?array {
        if (! $this->isEnabled()) {
            Log::debug('[AIService] AI is not enabled for notification generation');

            return null;
        }

        // Check daily cost limit
        if ($this->orchestrator->isOverDailyLimit()) {
            Log::info('[AIService] Daily cost limit reached for notification');

            return null;
        }

        $prompt = $this->buildNotificationPrompt($category, $priority, $context, $language);
        $systemPrompt = $this->getSystemPrompt($language);

        try {
            $response = $this->orchestrator->complete($prompt, [
                'system' => $systemPrompt,
                'max_tokens' => 400,
                'temperature' => 0.7,
            ]);

            if ($response->success) {
                $content = $this->parseNotificationResponse($response->content);

                if ($content) {
                    Log::info('[AIService] Notification content generated', [
                        'provider' => $response->provider,
                        'category' => $category,
                        'cost' => '$' . number_format($response->cost, 6),
                    ]);

                    return $content;
                }
            }

            Log::warning('[AIService] Notification generation failed', [
                'error' => $response->error ?? 'Parse failed',
            ]);
        } catch (\Exception $e) {
            Log::error('[AIService] Exception during notification generation', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build the notification prompt.
     */
    protected function buildNotificationPrompt(
        string $category,
        string $priority,
        string $context,
        string $language
    ): string {
        $lang = $language === 'id' ? 'Indonesian' : 'English';

        $categoryDesc = match ($category) {
            'maintenance' => 'System maintenance or downtime notification',
            'announcement' => 'General announcement to users',
            'update' => 'Important system update or change notification',
            'security' => 'Security-related notice or alert',
            default => 'General notification',
        };

        $priorityDesc = match ($priority) {
            'high' => 'This is HIGH priority - use urgent, attention-grabbing language',
            'critical' => 'This is CRITICAL - use very urgent language, emphasize importance',
            default => 'Normal priority - use professional, friendly tone',
        };

        $contextInfo = $context ? "Additional context: {$context}" : '';

        return <<<PROMPT
Generate a professional notification message for a web application.

Category: {$category} - {$categoryDesc}
Priority: {$priority} - {$priorityDesc}
Language: {$lang}
{$contextInfo}

Return ONLY valid JSON with this exact structure:
{
    "title": "Maximum 100 characters, clear and attention-appropriate title",
    "message": "<p>Well-structured HTML message with 2-4 paragraphs. Use <strong> for emphasis, <ul> for lists if needed.</p>"
}

Requirements:
- Title should match the priority level (urgent for high/critical)
- Message should be informative and professional
- Include relevant details based on category
- HTML must use only: p, ul, ol, li, strong, em, br tags
- Be specific, avoid generic phrases
PROMPT;
    }

    /**
     * Parse the notification response.
     *
     * @return array{title: string, message: string}|null
     */
    protected function parseNotificationResponse(string $response): ?array
    {
        $response = preg_replace('/```json\s*/', '', $response) ?? $response;
        $response = preg_replace('/```\s*/', '', $response) ?? $response;
        $response = trim($response);

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                return null;
            }

            $title = $data['title'] ?? '';
            $message = $data['message'] ?? '';

            if (empty($title) || empty($message)) {
                return null;
            }

            return [
                'title' => mb_substr(strip_tags($title), 0, 200),
                'message' => strip_tags($message, '<p><ul><ol><li><strong><em><br>'),
            ];
        } catch (\JsonException $e) {
            Log::warning('[AIService] Failed to parse notification JSON', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate project information using AI.
     *
     * @param  string  $projectName  Current or suggested project name
     * @param  string  $context  Additional context (e.g., existing description, industry)
     * @param  string  $language  Target language (en, id)
     * @return array{name: string, description: string}|null
     */
    public function generateProjectContent(
        string $projectName = '',
        string $context = '',
        string $language = 'en'
    ): ?array {
        if (! $this->isEnabled()) {
            Log::debug('[AIService] AI is not enabled for project generation');

            return null;
        }

        if ($this->orchestrator->isOverDailyLimit()) {
            Log::info('[AIService] Daily cost limit reached for project');

            return null;
        }

        $prompt = $this->buildProjectPrompt($projectName, $context, $language);
        $systemPrompt = $this->getSystemPrompt($language);

        try {
            $response = $this->orchestrator->complete($prompt, [
                'system' => $systemPrompt,
                'max_tokens' => 300,
                'temperature' => 0.7,
            ]);

            if ($response->success) {
                $content = $this->parseProjectResponse($response->content);

                if ($content) {
                    Log::info('[AIService] Project content generated', [
                        'provider' => $response->provider,
                        'cost' => '$' . number_format($response->cost, 6),
                    ]);

                    return $content;
                }
            }

            Log::warning('[AIService] Project generation failed', [
                'error' => $response->error ?? 'Parse failed',
            ]);
        } catch (\Exception $e) {
            Log::error('[AIService] Exception during project generation', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build the project prompt.
     */
    protected function buildProjectPrompt(string $projectName, string $context, string $language): string
    {
        $lang = $language === 'id' ? 'Indonesian' : 'English';
        $nameInfo = $projectName ? "Current project name: {$projectName}" : 'No project name provided';
        $contextInfo = $context ? "Additional context: {$context}" : '';

        return <<<PROMPT
Generate professional project branding content for a web application.

{$nameInfo}
{$contextInfo}
Language: {$lang}

Return ONLY valid JSON with this exact structure:
{
    "name": "Professional project/application name (max 60 characters). If current name is good, improve or keep it.",
    "description": "<p>Compelling project description in HTML format. 2-3 sentences describing the application's purpose, target audience, and key benefits.</p>"
}

Requirements:
- Name should be memorable, professional, and suitable for branding
- Description should be concise but informative
- Focus on value proposition and key features
- HTML must use only: p, strong, em tags
- Be creative but professional
PROMPT;
    }

    /**
     * Parse the project response.
     *
     * @return array{name: string, description: string}|null
     */
    protected function parseProjectResponse(string $response): ?array
    {
        $response = preg_replace('/```json\s*/', '', $response) ?? $response;
        $response = preg_replace('/```\s*/', '', $response) ?? $response;
        $response = trim($response);

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($data)) {
                return null;
            }

            $name = $data['name'] ?? '';
            $description = $data['description'] ?? '';

            if (empty($name)) {
                return null;
            }

            return [
                'name' => mb_substr(strip_tags($name), 0, 120),
                'description' => strip_tags($description, '<p><strong><em>'),
            ];
        } catch (\JsonException $e) {
            Log::warning('[AIService] Failed to parse project JSON', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Test AI connection by testing all providers.
     *
     * @return array{success: bool, message: string, providers?: array}
     */
    public function testConnection(): array
    {
        if (! $this->settings) {
            return [
                'success' => false,
                'message' => 'System settings not found',
            ];
        }

        $results = $this->orchestrator->testAllProviders();

        if (empty($results)) {
            return [
                'success' => false,
                'message' => 'No AI providers are configured. Please add at least one API key in System Settings.',
                'providers' => [],
            ];
        }

        $healthyCount = count(array_filter($results, fn ($r) => $r['healthy']));
        $totalCount = count($results);

        if ($healthyCount === 0) {
            $errors = array_map(fn ($r) => "{$r['name']}: {$r['error']}", $results);

            return [
                'success' => false,
                'message' => 'All providers failed: ' . implode('; ', $errors),
                'providers' => $results,
            ];
        }

        return [
            'success' => true,
            'message' => "{$healthyCount}/{$totalCount} providers are healthy",
            'providers' => $results,
        ];
    }

    /**
     * Get current usage statistics.
     *
     * @return array{
     *     today_cost: float,
     *     today_requests: int,
     *     today_tokens: int,
     *     daily_limit: float,
     *     remaining_budget: float,
     *     percentage: float,
     *     providers_available: int,
     *     providers_total: int,
     *     best_provider: string|null
     * }
     */
    public function getUsageStats(): array
    {
        $summary = $this->orchestrator->getSummary();
        $usage = $this->orchestrator->getTodayUsage();

        $percentage = $summary['daily_cost_limit'] > 0
            ? round(($usage['cost'] / $summary['daily_cost_limit']) * 100, 2)
            : 0;

        return [
            'today_cost' => round($usage['cost'], 6),
            'today_requests' => $usage['requests'],
            'today_tokens' => $usage['tokens'],
            'daily_limit' => $summary['daily_cost_limit'],
            'remaining_budget' => round($summary['remaining_budget'], 6),
            'percentage' => min(100, $percentage),
            'providers_available' => $summary['providers_available'],
            'providers_total' => $summary['providers_configured'],
            'best_provider' => $summary['best_provider'],
            'providers_breakdown' => $usage['providers'] ?? [],
        ];
    }

    /**
     * Clear cached AI content.
     */
    public function clearCache(): void
    {
        foreach (['global', 'allowlist', 'denylist'] as $mode) {
            foreach (['en', 'id'] as $language) {
                Cache::forget($this->getCacheKey('maintenance', $mode, $language));
            }
        }
        Cache::forget('ai_service_settings');

        // Also clear orchestrator health cache
        $this->orchestrator->clearHealthCache();

        Log::info('[AIService] Cache cleared');
    }

    /**
     * Get formatted status for display.
     */
    public function getStatusForDisplay(): array
    {
        $stats = $this->getUsageStats();

        return [
            'enabled' => $this->isEnabled(),
            'has_providers' => $stats['providers_total'] > 0,
            'providers_available' => $stats['providers_available'],
            'providers_total' => $stats['providers_total'],
            'best_provider' => $stats['best_provider'],
            'budget_used' => '$' . number_format($stats['today_cost'], 4),
            'budget_limit' => '$' . number_format($stats['daily_limit'], 2),
            'budget_remaining' => '$' . number_format($stats['remaining_budget'], 4),
            'budget_percentage' => $stats['percentage'],
            'requests_today' => $stats['today_requests'],
        ];
    }
}

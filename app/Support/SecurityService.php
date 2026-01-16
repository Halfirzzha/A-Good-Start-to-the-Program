<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Enterprise Security Service
 *
 * Comprehensive security management with AI integration and professional fallback:
 * - IP blocklist/whitelist management with CIDR support
 * - Session fingerprinting and hijacking detection
 * - Input sanitization and threat detection
 * - AI-enhanced threat analysis with fallback patterns
 * - Role-based access control integration
 *
 * @version 1.2.4
 */
class SecurityService
{
    // =========================================================================
    // Configuration Constants
    // =========================================================================

    private const CACHE_PREFIX = 'security:';
    private const BLOCKED_IPS_KEY = 'security:ip_blocklist';
    private const WHITELISTED_IPS_KEY = 'security:ip_whitelist';
    private const THREAT_COUNTER_PREFIX = 'security:threats:';
    private const SESSION_FINGERPRINT_KEY = 'security_fingerprint';
    private const SESSION_CREATED_KEY = 'security_session_created';
    private const SESSION_LAST_ACTIVITY_KEY = 'security_last_activity';
    private const SESSION_IP_KEY = 'security_session_ip';
    private const ACTIVE_SESSIONS_PREFIX = 'security:sessions:';

    /**
     * Dangerous patterns for threat detection
     */
    private const DANGEROUS_PATTERNS = [
        'xss' => [
            '/<script\b/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe\b/i',
            '/<object\b/i',
            '/<embed\b/i',
            '/expression\s*\(/i',
        ],
        'sqli' => [
            '/\bunion\b.*\bselect\b/i',
            '/\bor\b\s+[\'"]\d+[\'"]\s*=\s*[\'"]\d+/i',
            '/[\'"];\s*(drop|delete|update|insert)\b/i',
            '/\bexec\b\s*\(/i',
            '/\bsleep\s*\(/i',
            '/\bwaitfor\b.*\bdelay\b/i',
        ],
        'path_traversal' => [
            '/\.\.\//',
            '/\.\.\\\\/',
            '/%2e%2e%2f/i',
            '/%2e%2e%5c/i',
        ],
        'command_injection' => [
            '/[;&|`$]/',
            '/\|\|/',
            '/\$\(/',
        ],
    ];

    /**
     * Sensitive field names for redaction
     */
    private const SENSITIVE_FIELDS = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'api_key', 'secret', 'credit_card', 'cvv', 'ssn',
        'two_factor_secret', 'two_factor_recovery_codes', 'private_key',
    ];

    // =========================================================================
    // IP Blocklist Management
    // =========================================================================

    /**
     * Check if an IP address is blocked
     */
    public static function isIpBlocked(string $ip): bool
    {
        if (self::isIpWhitelisted($ip)) {
            return false;
        }

        $key = self::CACHE_PREFIX . 'blocked:' . self::hashIp($ip);

        return self::getCache()->has($key);
    }

    /**
     * Check if an IP is whitelisted
     */
    public static function isIpWhitelisted(string $ip): bool
    {
        $whitelist = self::getIpWhitelist();

        if (in_array($ip, $whitelist, true)) {
            return true;
        }

        foreach ($whitelist as $range) {
            if (str_contains($range, '/') && self::ipInCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Block an IP address
     */
    public static function blockIp(
        string $ip,
        string $reason = 'Security threat detected',
        ?int $minutes = null,
        array $metadata = []
    ): bool {
        if (self::isIpWhitelisted($ip)) {
            Log::warning('[SecurityService] Attempted to block whitelisted IP', [
                'ip_hash' => self::hashIp($ip),
                'reason' => $reason,
            ]);

            return false;
        }

        $minutes = $minutes ?? (int) config('security.threat_detection.ip_block_minutes', 45);
        $key = self::CACHE_PREFIX . 'blocked:' . self::hashIp($ip);

        $blockData = [
            'ip_hash' => self::hashIp($ip),
            'reason' => $reason,
            'blocked_at' => now()->toIso8601String(),
            'expires_at' => $minutes > 0 ? now()->addMinutes($minutes)->toIso8601String() : null,
            'permanent' => $minutes <= 0,
            'metadata' => $metadata,
        ];

        $ttl = $minutes > 0 ? now()->addMinutes($minutes) : now()->addYear();
        self::getCache()->put($key, $blockData, $ttl);
        self::addToBlockedIndex($ip);

        Log::warning('[SecurityService] IP blocked', [
            'ip_hash' => self::hashIp($ip),
            'reason' => $reason,
            'minutes' => $minutes,
        ]);

        return true;
    }

    /**
     * Unblock an IP address
     */
    public static function unblockIp(string $ip): bool
    {
        $key = self::CACHE_PREFIX . 'blocked:' . self::hashIp($ip);
        $wasBlocked = self::getCache()->has($key);

        self::getCache()->forget($key);
        self::removeFromBlockedIndex($ip);

        if ($wasBlocked) {
            Log::info('[SecurityService] IP unblocked', ['ip_hash' => self::hashIp($ip)]);
        }

        return $wasBlocked;
    }

    /**
     * Add IP to whitelist
     */
    public static function whitelistIp(string $ip): void
    {
        $whitelist = self::getIpWhitelist();

        if (!in_array($ip, $whitelist, true)) {
            $whitelist[] = $ip;
            self::getCache()->forever(self::WHITELISTED_IPS_KEY, $whitelist);
            Log::info('[SecurityService] IP whitelisted', ['ip_hash' => self::hashIp($ip)]);
        }

        self::unblockIp($ip);
    }

    /**
     * Get IP whitelist
     */
    public static function getIpWhitelist(): array
    {
        $cached = self::getCache()->get(self::WHITELISTED_IPS_KEY, []);
        $config = config('security.ip_whitelist', []);

        return array_unique(array_merge($cached, $config));
    }

    /**
     * Get blocklist statistics
     */
    public static function getBlocklistStats(): array
    {
        $blocked = self::getCache()->get(self::BLOCKED_IPS_KEY, []);
        $whitelist = self::getIpWhitelist();

        return [
            'blocked_count' => count($blocked),
            'whitelisted_count' => count($whitelist),
            'blocked_hashes' => $blocked,
        ];
    }

    // =========================================================================
    // Session Security
    // =========================================================================

    /**
     * Initialize session security for a new session
     */
    public static function initializeSession(Request $request, int $userId): void
    {
        $session = $request->session();
        $fingerprint = self::generateSessionFingerprint($request);

        $session->put(self::SESSION_FINGERPRINT_KEY, $fingerprint);
        $session->put(self::SESSION_CREATED_KEY, now()->toIso8601String());
        $session->put(self::SESSION_LAST_ACTIVITY_KEY, now()->toIso8601String());
        $session->put(self::SESSION_IP_KEY, $request->ip());

        self::trackActiveSession($userId, $session->getId(), [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'fingerprint_hash' => hash('sha256', $fingerprint),
            'created_at' => now()->toIso8601String(),
        ]);

        Log::debug('[SecurityService] Session initialized', [
            'user_id' => $userId,
            'session_hash' => self::hashSessionId($session->getId()),
        ]);
    }

    /**
     * Validate current session security
     */
    public static function validateSession(Request $request): array
    {
        $session = $request->session();
        $result = ['valid' => true, 'warnings' => [], 'errors' => []];

        // Check fingerprint
        $storedFingerprint = $session->get(self::SESSION_FINGERPRINT_KEY);
        if ($storedFingerprint) {
            $currentFingerprint = self::generateSessionFingerprint($request);

            if (!hash_equals($storedFingerprint, $currentFingerprint)) {
                $result['valid'] = false;
                $result['errors'][] = 'Session fingerprint mismatch - possible session hijacking';
            }
        }

        // Check IP change
        $storedIp = $session->get(self::SESSION_IP_KEY);
        if ($storedIp && $storedIp !== $request->ip()) {
            if (!self::isSameSubnet($storedIp, $request->ip())) {
                $result['warnings'][] = 'Session IP changed significantly';
            }
        }

        // Check session age
        $createdAt = $session->get(self::SESSION_CREATED_KEY);
        if ($createdAt) {
            $maxAge = config('session.lifetime', 120) * 2;
            $created = \Carbon\Carbon::parse($createdAt);

            if ($created->diffInMinutes(now()) > $maxAge) {
                $result['valid'] = false;
                $result['errors'][] = 'Session exceeded maximum age';
            }
        }

        $session->put(self::SESSION_LAST_ACTIVITY_KEY, now()->toIso8601String());

        return $result;
    }

    /**
     * Generate session fingerprint
     */
    public static function generateSessionFingerprint(Request $request): string
    {
        $components = [
            $request->userAgent() ?? 'unknown',
            $request->header('Accept-Language', 'unknown'),
            $request->header('Accept-Encoding', 'unknown'),
        ];

        return hash('sha256', implode('|', $components) . config('app.key'));
    }

    /**
     * Track active session
     */
    public static function trackActiveSession(int $userId, string $sessionId, array $metadata = []): void
    {
        $key = self::ACTIVE_SESSIONS_PREFIX . $userId;
        $sessions = Cache::get($key, []);

        $sessions[$sessionId] = array_merge($metadata, [
            'last_activity' => now()->toIso8601String(),
        ]);

        if (count($sessions) > 10) {
            $sessions = array_slice($sessions, -10, 10, true);
        }

        $ttl = (config('session.lifetime', 120) + 60) * 60;
        Cache::put($key, $sessions, $ttl);
    }

    /**
     * Get active sessions for a user
     */
    public static function getActiveSessions(int $userId): array
    {
        return Cache::get(self::ACTIVE_SESSIONS_PREFIX . $userId, []);
    }

    /**
     * Invalidate all sessions except current
     */
    public static function invalidateAllSessions(int $userId, ?string $exceptSessionId = null): int
    {
        $key = self::ACTIVE_SESSIONS_PREFIX . $userId;
        $sessions = Cache::get($key, []);
        $count = 0;

        foreach (array_keys($sessions) as $sessionId) {
            if ($sessionId !== $exceptSessionId) {
                unset($sessions[$sessionId]);
                $count++;
            }
        }

        Cache::put($key, $sessions, (config('session.lifetime', 120) + 60) * 60);

        Log::info('[SecurityService] Sessions invalidated', [
            'user_id' => $userId,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Regenerate session securely
     */
    public static function regenerateSession(Request $request): void
    {
        $session = $request->session();

        $preserved = [
            self::SESSION_FINGERPRINT_KEY => $session->get(self::SESSION_FINGERPRINT_KEY),
            self::SESSION_CREATED_KEY => $session->get(self::SESSION_CREATED_KEY),
            self::SESSION_IP_KEY => $session->get(self::SESSION_IP_KEY),
        ];

        $session->regenerate(true);

        foreach ($preserved as $key => $value) {
            if ($value) {
                $session->put($key, $value);
            }
        }

        $session->put(self::SESSION_LAST_ACTIVITY_KEY, now()->toIso8601String());
    }

    // =========================================================================
    // Input Sanitization
    // =========================================================================

    /**
     * Sanitize string for safe output
     */
    public static function sanitizeString(string $input, bool $allowHtml = false): string
    {
        $input = str_replace(["\0", '%00'], '', $input);
        $input = trim($input);

        if ($allowHtml) {
            return strip_tags($input, '<p><br><strong><em><ul><ol><li><a><h1><h2><h3><h4><h5><h6>');
        }

        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Sanitize filename
     */
    public static function sanitizeFilename(string $filename): string
    {
        $filename = str_replace(['../', '..\\', '/', '\\'], '', $filename);
        $filename = str_replace(["\0", '%00'], '', $filename);
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        $filename = ltrim($filename ?? '', '.');

        return Str::limit($filename, 200, '');
    }

    /**
     * Sanitize email
     */
    public static function sanitizeEmail(string $email): string
    {
        $email = preg_replace('/[<>"\'\x00]/', '', $email);
        $email = filter_var(trim($email ?? ''), FILTER_SANITIZE_EMAIL);

        return $email ?: '';
    }

    /**
     * Sanitize URL
     */
    public static function sanitizeUrl(string $url): string
    {
        $url = preg_replace('/^(javascript|vbscript|data):/i', '', $url);
        $url = filter_var(trim($url ?? ''), FILTER_SANITIZE_URL);

        return $url ?: '';
    }

    /**
     * Sanitize IP address
     */
    public static function sanitizeIpAddress(string $ip): ?string
    {
        $ip = trim($ip);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    /**
     * Sanitize user agent
     */
    public static function sanitizeUserAgent(string $userAgent): string
    {
        $userAgent = preg_replace('/[\x00-\x1F\x7F]/', '', $userAgent);

        return Str::limit($userAgent ?? '', 500, '');
    }

    /**
     * Redact sensitive fields from array
     */
    public static function redactSensitiveFields(array $data, array $additionalKeys = []): array
    {
        $sensitiveKeys = array_merge(self::SENSITIVE_FIELDS, $additionalKeys);

        return self::redactRecursive($data, $sensitiveKeys);
    }

    /**
     * Hash value for safe logging
     */
    public static function hashForLogging(string $value): string
    {
        if (empty($value)) {
            return '[empty]';
        }

        $prefix = Str::limit($value, 3, '');
        $hash = substr(hash('sha256', $value), 0, 8);

        return $prefix . '***' . $hash;
    }

    // =========================================================================
    // Threat Detection (AI-Enhanced with Fallback)
    // =========================================================================

    /**
     * Analyze request for security threats with AI enhancement
     *
     * Uses AI for enhanced analysis when available, with professional pattern-based fallback
     */
    public static function analyzeRequest(Request $request, bool $useAI = true): array
    {
        $threats = [];

        // Pattern-based detection (always runs - professional fallback)
        foreach ($request->query() as $key => $value) {
            if (is_string($value)) {
                $detected = self::detectThreatsInValue($value);
                foreach ($detected as $type) {
                    $threats[] = ['type' => $type, 'location' => 'query', 'field' => $key];
                }
            }
        }

        if ($request->isMethod('POST') && !$request->hasFile('*')) {
            $input = $request->except(['password', 'password_confirmation', '_token']);

            foreach ($input as $key => $value) {
                if (is_string($value) && strlen($value) < 10000) {
                    $detected = self::detectThreatsInValue($value);
                    foreach ($detected as $type) {
                        $threats[] = ['type' => $type, 'location' => 'body', 'field' => $key];
                    }
                }
            }
        }

        // Check headers
        foreach (['X-Forwarded-For', 'X-Real-IP', 'Referer', 'User-Agent'] as $header) {
            $value = $request->header($header);
            if ($value && is_string($value)) {
                $detected = self::detectThreatsInValue($value);
                foreach ($detected as $type) {
                    $threats[] = ['type' => $type, 'location' => 'header', 'field' => $header];
                }
            }
        }

        // AI-enhanced analysis (optional, with fallback)
        $aiAnalysis = null;
        if ($useAI && count($threats) > 0) {
            $aiAnalysis = self::getAIThreatAnalysis($threats, $request);
        }

        $result = [
            'threat_detected' => count($threats) > 0,
            'threats' => $threats,
            'threat_count' => count($threats),
            'severity' => self::calculateThreatSeverity($threats),
            'ai_analysis' => $aiAnalysis,
            'analyzed_at' => now()->toIso8601String(),
        ];

        // Track threat for auto-blocking
        if (count($threats) > 0) {
            self::trackThreat($request->ip(), $threats);
        }

        return $result;
    }

    /**
     * Detect threats in a value using pattern matching
     */
    public static function detectThreatsInValue(string $input): array
    {
        $detected = [];

        foreach (self::DANGEROUS_PATTERNS as $type => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $input)) {
                    $detected[] = $type;
                    break;
                }
            }
        }

        return $detected;
    }

    /**
     * Detect XSS patterns
     */
    public static function detectXss(string $input): bool
    {
        foreach (self::DANGEROUS_PATTERNS['xss'] as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect SQL injection patterns
     */
    public static function detectSqlInjection(string $input): bool
    {
        foreach (self::DANGEROUS_PATTERNS['sqli'] as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect path traversal
     */
    public static function detectPathTraversal(string $input): bool
    {
        foreach (self::DANGEROUS_PATTERNS['path_traversal'] as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get AI-enhanced threat analysis (with fallback)
     */
    private static function getAIThreatAnalysis(array $threats, Request $request): ?array
    {
        try {
            $aiService = app(AIService::class);

            if (!$aiService->isEnabled()) {
                return self::getFallbackThreatAnalysis($threats);
            }

            $orchestrator = $aiService->getOrchestrator();

            if ($orchestrator->isOverDailyLimit()) {
                return self::getFallbackThreatAnalysis($threats);
            }

            $prompt = self::buildThreatAnalysisPrompt($threats, $request);

            $response = $orchestrator->complete($prompt, [
                'system' => 'You are a cybersecurity expert. Analyze threats and provide JSON response only.',
                'max_tokens' => 300,
                'temperature' => 0.3,
            ]);

            if ($response->success) {
                $parsed = self::parseThreatAnalysisResponse($response->content);
                if ($parsed) {
                    $parsed['source'] = 'ai';
                    $parsed['provider'] = $response->provider;

                    return $parsed;
                }
            }

            return self::getFallbackThreatAnalysis($threats);
        } catch (\Throwable $e) {
            Log::warning('[SecurityService] AI threat analysis failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            return self::getFallbackThreatAnalysis($threats);
        }
    }

    /**
     * Professional fallback threat analysis (when AI unavailable)
     */
    private static function getFallbackThreatAnalysis(array $threats): array
    {
        $threatTypes = array_unique(array_column($threats, 'type'));
        $severity = self::calculateThreatSeverity($threats);

        $recommendations = [];
        $riskLevel = 'low';

        if (in_array('sqli', $threatTypes)) {
            $recommendations[] = 'SQL Injection attempt detected. Validate and parameterize all database queries.';
            $riskLevel = 'high';
        }

        if (in_array('xss', $threatTypes)) {
            $recommendations[] = 'Cross-Site Scripting (XSS) attempt detected. Sanitize all user inputs.';
            $riskLevel = max($riskLevel, 'medium');
        }

        if (in_array('path_traversal', $threatTypes)) {
            $recommendations[] = 'Path traversal attempt detected. Validate file paths and restrict access.';
            $riskLevel = 'high';
        }

        if (in_array('command_injection', $threatTypes)) {
            $recommendations[] = 'Command injection attempt detected. Never execute user input directly.';
            $riskLevel = 'critical';
        }

        if (count($threats) >= 3) {
            $recommendations[] = 'Multiple attack vectors detected. Consider blocking this IP temporarily.';
            $riskLevel = 'critical';
        }

        return [
            'source' => 'fallback',
            'risk_level' => $riskLevel,
            'threat_types' => $threatTypes,
            'severity_score' => $severity,
            'recommendations' => $recommendations,
            'action' => $riskLevel === 'critical' ? 'block' : ($riskLevel === 'high' ? 'monitor' : 'log'),
        ];
    }

    /**
     * Build prompt for AI threat analysis
     */
    private static function buildThreatAnalysisPrompt(array $threats, Request $request): string
    {
        $threatSummary = json_encode($threats);

        return <<<PROMPT
Analyze these security threats detected in a web request:

Threats: {$threatSummary}
Request Path: {$request->path()}
Request Method: {$request->method()}

Return ONLY valid JSON:
{
    "risk_level": "low|medium|high|critical",
    "severity_score": 1-10,
    "recommendations": ["recommendation1", "recommendation2"],
    "action": "log|monitor|block"
}
PROMPT;
    }

    /**
     * Parse AI threat analysis response
     */
    private static function parseThreatAnalysisResponse(string $response): ?array
    {
        $response = preg_replace('/```json\s*/', '', $response) ?? $response;
        $response = preg_replace('/```\s*/', '', $response) ?? $response;
        $response = trim($response);

        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Calculate threat severity score
     */
    private static function calculateThreatSeverity(array $threats): int
    {
        if (empty($threats)) {
            return 0;
        }

        $weights = [
            'command_injection' => 10,
            'sqli' => 9,
            'path_traversal' => 8,
            'xss' => 7,
        ];

        $maxSeverity = 0;
        foreach ($threats as $threat) {
            $type = $threat['type'] ?? '';
            $maxSeverity = max($maxSeverity, $weights[$type] ?? 5);
        }

        return min(10, $maxSeverity + min(count($threats) - 1, 3));
    }

    /**
     * Track threat for rate limiting
     */
    private static function trackThreat(string $ip, array $threats): void
    {
        $key = self::THREAT_COUNTER_PREFIX . self::hashIp($ip);
        $count = (int) self::getCache()->get($key, 0);

        self::getCache()->put($key, $count + 1, now()->addHour());

        // Auto-block after threshold
        $threshold = (int) config('security.threat_detection.block_threshold', 5);
        if ($count + 1 >= $threshold) {
            self::blockIp($ip, 'Automated block: Multiple threat detections', 60);

            Log::alert('[SecurityService] IP auto-blocked due to repeated threats', [
                'ip_hash' => self::hashIp($ip),
                'threat_count' => $count + 1,
            ]);
        }
    }

    // =========================================================================
    // Permission & Role Integration
    // =========================================================================

    /**
     * Check if user has security management permission
     */
    public static function canManageSecurity(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_security') || $user->can('view_any_audit_log');
    }

    /**
     * Check if user can view security logs
     */
    public static function canViewSecurityLogs(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('view_any_audit_log') || $user->can('view_security_logs');
    }

    /**
     * Check if user can manage IP blocklist
     */
    public static function canManageBlocklist(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ($user->isDeveloper()) {
            return true;
        }

        return $user->can('manage_security') || $user->can('manage_ip_blocklist');
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Hash IP for privacy-safe storage
     */
    public static function hashIp(string $ip): string
    {
        return hash('sha256', $ip . config('app.key'));
    }

    /**
     * Hash session ID for safe logging
     */
    public static function hashSessionId(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 16);
    }

    /**
     * Check if two IPs are in same /16 subnet
     */
    private static function isSameSubnet(string $ip1, string $ip2): bool
    {
        if (!filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
            !filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        $parts1 = explode('.', $ip1);
        $parts2 = explode('.', $ip2);

        return $parts1[0] === $parts2[0] && $parts1[1] === $parts2[1];
    }

    /**
     * Check if IP is in CIDR range
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            $mask = -1 << (32 - $bits);

            return ($ip & $mask) === ($subnet & $mask);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBin = inet_pton($ip);
            $subnetBin = inet_pton($subnet);

            if ($ipBin === false || $subnetBin === false) {
                return false;
            }

            $ipHex = bin2hex($ipBin);
            $subnetHex = bin2hex($subnetBin);
            $ipBits = $subnetBits = '';

            for ($i = 0; $i < strlen($ipHex); $i++) {
                $ipBits .= str_pad(base_convert($ipHex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
                $subnetBits .= str_pad(base_convert($subnetHex[$i], 16, 2), 4, '0', STR_PAD_LEFT);
            }

            return substr($ipBits, 0, $bits) === substr($subnetBits, 0, $bits);
        }

        return false;
    }

    /**
     * Recursively redact sensitive data
     */
    private static function redactRecursive(array $data, array $sensitiveKeys): array
    {
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string) $key);

            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($keyLower, strtolower($sensitive))) {
                    $data[$key] = '[REDACTED]';
                    continue 2;
                }
            }

            if (is_array($value)) {
                $data[$key] = self::redactRecursive($value, $sensitiveKeys);
            }
        }

        return $data;
    }

    /**
     * Add IP to blocked index
     */
    private static function addToBlockedIndex(string $ip): void
    {
        $hash = self::hashIp($ip);
        $blocked = self::getCache()->get(self::BLOCKED_IPS_KEY, []);

        if (!in_array($hash, $blocked, true)) {
            $blocked[] = $hash;
            self::getCache()->forever(self::BLOCKED_IPS_KEY, $blocked);
        }
    }

    /**
     * Remove IP from blocked index
     */
    private static function removeFromBlockedIndex(string $ip): void
    {
        $hash = self::hashIp($ip);
        $blocked = self::getCache()->get(self::BLOCKED_IPS_KEY, []);
        $blocked = array_values(array_filter($blocked, fn ($h) => $h !== $hash));

        self::getCache()->forever(self::BLOCKED_IPS_KEY, $blocked);
    }

    /**
     * Get cache store
     */
    private static function getCache(): \Illuminate\Contracts\Cache\Repository
    {
        $store = config('security.threat_detection.cache_store', 'rate_limit');

        try {
            return Cache::store($store);
        } catch (\Exception) {
            return Cache::store('file');
        }
    }
}

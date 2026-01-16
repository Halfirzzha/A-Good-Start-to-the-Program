<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Support\AI\AIOrchestrator;
use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;

/**
 * AI Provider Health Dashboard Widget
 *
 * Displays real-time status of all configured AI providers:
 * - Provider health and availability
 * - Daily usage and cost tracking
 * - Failover status
 */
class AIProviderHealthWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.ai-provider-health';

    public array $providers = [];

    public array $summary = [];

    public array $usage = [];

    public bool $loading = true;

    public function mount(): void
    {
        $this->refreshData();
    }

    public function refreshData(): void
    {
        $this->loading = true;

        try {
            $orchestrator = new AIOrchestrator;

            $this->summary = $orchestrator->getSummary();
            $this->usage = $orchestrator->getTodayUsage();
            $this->providers = $this->summary['providers'] ?? [];

        } catch (\Throwable $e) {
            $this->providers = [];
            $this->summary = [];
            $this->usage = [];
        }

        $this->loading = false;
    }

    public function testProvider(string $identifier): void
    {
        try {
            $orchestrator = new AIOrchestrator;
            $result = $orchestrator->getProvider($identifier)?->testConnection();

            if ($result && ($result['success'] ?? false)) {
                \Filament\Notifications\Notification::make()
                    ->success()
                    ->title("✅ {$identifier}")
                    ->body("Connected successfully (" . ($result['latency_ms'] ?? 0) . "ms)")
                    ->send();
            } else {
                \Filament\Notifications\Notification::make()
                    ->danger()
                    ->title("❌ {$identifier}")
                    ->body($result['message'] ?? $result['error'] ?? 'Connection failed')
                    ->send();
            }

            $this->refreshData();
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title('Error')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function testAllProviders(): void
    {
        try {
            $orchestrator = new AIOrchestrator;
            $results = $orchestrator->testAllProviders();

            $healthy = count(array_filter($results, fn ($r) => $r['healthy']));
            $total = count($results);

            \Filament\Notifications\Notification::make()
                ->title('Provider Health Check Complete')
                ->body("{$healthy}/{$total} providers are healthy")
                ->icon($healthy === $total ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                ->iconColor($healthy === $total ? 'success' : ($healthy > 0 ? 'warning' : 'danger'))
                ->send();

            $this->refreshData();
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title('Error')
                ->body($e->getMessage())
                ->send();
        }
    }

    public function clearCache(): void
    {
        try {
            $orchestrator = new AIOrchestrator;
            $orchestrator->clearHealthCache();

            \Filament\Notifications\Notification::make()
                ->success()
                ->title('Cache Cleared')
                ->body('AI provider cache has been cleared')
                ->send();

            $this->refreshData();
        } catch (\Throwable $e) {
            \Filament\Notifications\Notification::make()
                ->danger()
                ->title('Error')
                ->body($e->getMessage())
                ->send();
        }
    }

    protected function getViewData(): array
    {
        return [
            'providers' => $this->providers,
            'summary' => $this->summary,
            'usage' => $this->usage,
            'loading' => $this->loading,
        ];
    }
}

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-cpu-chip class="h-5 w-5 text-primary-500" />
                <span>AI Provider Status</span>
            </div>
        </x-slot>

        <x-slot name="headerEnd">
            <div class="flex items-center gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-m-arrow-path" wire:click="refreshData"
                    wire:loading.attr="disabled">
                    <span wire:loading.remove>Refresh</span>
                    <span wire:loading>Loading...</span>
                </x-filament::button>

                <x-filament::button size="sm" color="primary" icon="heroicon-m-beaker"
                    wire:click="testAllProviders">
                    Test All
                </x-filament::button>

                <x-filament::button size="sm" color="gray" icon="heroicon-m-trash" wire:click="clearCache">
                    Clear Cache
                </x-filament::button>
            </div>
        </x-slot>

        @if ($loading)
            <div class="flex items-center justify-center py-8">
                <x-filament::loading-indicator class="h-8 w-8" />
            </div>
        @else
            {{-- Summary Cards --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                {{-- Providers Status --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Providers</div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-green-600 dark:text-green-400">
                            {{ $summary['providers_available'] ?? 0 }}
                        </span>
                        <span class="text-gray-500 dark:text-gray-400">
                            / {{ $summary['providers_configured'] ?? 0 }} available
                        </span>
                    </div>
                </div>

                {{-- Today's Cost --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Today's Cost</div>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                            ${{ number_format($usage['cost'] ?? 0, 4) }}
                        </span>
                        <span class="text-gray-500 dark:text-gray-400">
                            / ${{ number_format($summary['daily_cost_limit'] ?? 10, 2) }}
                        </span>
                    </div>
                    @if (($summary['daily_cost_limit'] ?? 10) > 0)
                        @php
                            $percentage = min(
                                100,
                                (($usage['cost'] ?? 0) / ($summary['daily_cost_limit'] ?? 10)) * 100,
                            );
                        @endphp
                        <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                            <div class="h-2 rounded-full {{ $percentage > 80 ? 'bg-red-500' : ($percentage > 50 ? 'bg-yellow-500' : 'bg-green-500') }}"
                                style="width: {{ $percentage }}%"></div>
                        </div>
                    @endif
                </div>

                {{-- Today's Requests --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Today's Requests</div>
                    <div class="mt-1 text-2xl font-bold text-purple-600 dark:text-purple-400">
                        {{ number_format($usage['requests'] ?? 0) }}
                    </div>
                </div>

                {{-- Best Provider --}}
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4 bg-white dark:bg-gray-800">
                    <div class="text-sm font-medium text-gray-500 dark:text-gray-400">Best Provider</div>
                    <div class="mt-1 text-lg font-bold text-gray-900 dark:text-gray-100">
                        @if ($summary['best_provider'] ?? null)
                            <span class="inline-flex items-center gap-1">
                                <x-heroicon-s-check-circle class="h-5 w-5 text-green-500" />
                                {{ ucfirst($summary['best_provider']) }}
                            </span>
                        @else
                            <span class="text-gray-400">None available</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Provider Grid --}}
            @if (count($providers) > 0)
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                    @foreach ($providers as $provider)
                        <div
                            class="rounded-lg border {{ $provider['available'] ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20' : 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/20' }} p-4">
                            <div class="flex items-center justify-between mb-2">
                                <span class="font-semibold text-gray-900 dark:text-gray-100">
                                    {{ $provider['name'] ?? ucfirst($provider['identifier'] ?? 'Unknown') }}
                                </span>
                                @if ($provider['available'])
                                    <x-heroicon-s-check-circle class="h-5 w-5 text-green-500" />
                                @else
                                    <x-heroicon-s-x-circle class="h-5 w-5 text-red-500" />
                                @endif
                            </div>

                            <div class="text-xs text-gray-500 dark:text-gray-400 space-y-1">
                                <div class="flex justify-between">
                                    <span>Priority:</span>
                                    <span class="font-medium">{{ $provider['priority'] ?? 99 }}</span>
                                </div>
                                <div class="flex justify-between">
                                    <span>Status:</span>
                                    <span
                                        class="font-medium {{ $provider['available'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $provider['available'] ? 'Ready' : 'Unavailable' }}
                                    </span>
                                </div>
                            </div>

                            <div class="mt-3">
                                <x-filament::button size="xs" color="gray" class="w-full"
                                    wire:click="testProvider('{{ $provider['identifier'] }}')">
                                    Test Connection
                                </x-filament::button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <x-heroicon-o-cpu-chip class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-gray-100">No AI Providers Configured
                    </h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Configure at least one AI provider API key in System Settings.
                    </p>
                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                        Supported: OpenAI, Anthropic (Claude), Google Gemini, Groq, OpenRouter
                    </p>
                </div>
            @endif

            {{-- Features Info --}}
            <div class="mt-6 p-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800">
                <div class="flex items-start gap-3">
                    <x-heroicon-o-information-circle class="h-5 w-5 text-blue-500 shrink-0 mt-0.5" />
                    <div class="text-sm text-blue-700 dark:text-blue-300">
                        <strong>Enterprise AI Features:</strong>
                        <ul class="mt-1 space-y-1 text-blue-600 dark:text-blue-400">
                            <li>✓ Automatic failover between providers</li>
                            <li>✓ Circuit breaker for fault tolerance</li>
                            <li>✓ Smart provider selection</li>
                            <li>✓ Daily cost limits: ${{ number_format($summary['daily_cost_limit'] ?? 10, 2) }}/day
                            </li>
                            <li>✓ Response caching (24 hours)</li>
                        </ul>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

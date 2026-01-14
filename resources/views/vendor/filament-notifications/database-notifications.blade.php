@php
    use Filament\Support\Enums\Alignment;
    use Filament\Support\View\Components\BadgeComponent;
    use Illuminate\View\ComponentAttributeBag;

    $notifications = $this->getNotifications();
    $unreadNotificationsCount = $this->getUnreadNotificationsCount();
    $hasNotifications = $notifications->count();
    $isPaginated = $notifications instanceof \Illuminate\Contracts\Pagination\Paginator && $notifications->hasPages();
    $pollingInterval = $this->getPollingInterval();
    $canFilter = method_exists($this, 'getCategoryFilterOptions');
@endphp

<div class="fi-no-database">
    <x-filament::modal
        :alignment="$hasNotifications ? null : Alignment::Center"
        close-button
        :description="$hasNotifications ? null : __('filament-notifications::database.modal.empty.description')"
        :heading="$hasNotifications ? null : __('filament-notifications::database.modal.empty.heading')"
        :icon="$hasNotifications ? null : \Filament\Support\Icons\Heroicon::OutlinedBellSlash"
        :icon-alias="
            $hasNotifications
            ? null
            : \Filament\Notifications\View\NotificationsIconAlias::DATABASE_MODAL_EMPTY_STATE
        "
        :icon-color="$hasNotifications ? null : 'gray'"
        id="database-notifications"
        slide-over
        :sticky-header="$hasNotifications"
        teleport="body"
        width="md"
        class="fi-no-database"
        :attributes="
            new \Illuminate\View\ComponentAttributeBag([
                'wire:poll.' . $pollingInterval => $pollingInterval ? '' : false,
            ])
        "
    >
        @if ($trigger = $this->getTrigger())
            <x-slot name="trigger">
                {{ $trigger->with(['unreadNotificationsCount' => $unreadNotificationsCount]) }}
            </x-slot>
        @endif

        @if ($hasNotifications)
            <x-slot name="header">
                <div class="flex flex-col gap-4">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="fi-modal-heading">
                                {{ __('filament-notifications::database.modal.heading') }}

                                @if ($unreadNotificationsCount)
                                    <span
                                        {{
                                            (new ComponentAttributeBag)->color(BadgeComponent::class, 'primary')->class([
                                                'fi-badge fi-size-xs',
                                            ])
                                        }}
                                    >
                                        {{ $unreadNotificationsCount }}
                                    </span>
                                @endif
                            </h2>
                        </div>

                        <div class="fi-ac">
                            @if ($unreadNotificationsCount && $this->markAllNotificationsAsReadAction?->isVisible())
                                {{ $this->markAllNotificationsAsReadAction }}
                            @endif

                            @if ($this->clearNotificationsAction?->isVisible())
                                {{ $this->clearNotificationsAction }}
                            @endif
                        </div>
                    </div>

                    @if ($canFilter)
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                <span>{{ __('notifications.ui.inbox.category') }}</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="categoryFilter">
                                        <option value="">{{ __('notifications.ui.filters.category') }}</option>
                                        @foreach ($this->getCategoryFilterOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>

                            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                <span>{{ __('notifications.ui.inbox.priority') }}</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="priorityFilter">
                                        <option value="">{{ __('notifications.ui.filters.priority') }}</option>
                                        @foreach ($this->getPriorityFilterOptions() as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>

                            <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                                <span>{{ __('notifications.ui.inbox.read_status') }}</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model.live="readFilter">
                                        <option value="">{{ __('notifications.ui.filters.read_all') }}</option>
                                        <option value="unread">{{ __('notifications.ui.filters.read_unread') }}</option>
                                        <option value="read">{{ __('notifications.ui.filters.read_read') }}</option>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                        </div>

                        <div class="flex justify-end">
                            <x-filament::button
                                color="gray"
                                size="sm"
                                outlined
                                wire:click="clearFilters"
                            >
                                {{ __('notifications.ui.filters.clear') }}
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </x-slot>

            @foreach ($notifications as $notification)
                <div
                    @class([
                        'fi-no-notification-read-ctn' => ! $notification->unread(),
                        'fi-no-notification-unread-ctn' => $notification->unread(),
                    ])
                >
                    {{ $this->getNotification($notification)->inline() }}
                </div>
            @endforeach

            @if ($broadcastChannel = $this->getBroadcastChannel())
                @script
                    <script>
                        window.addEventListener('EchoLoaded', () => {
                            window.Echo.private(@js($broadcastChannel)).listen(
                                '.database-notifications.sent',
                                () => {
                                    setTimeout(
                                        () => $wire.call('$refresh'),
                                        500,
                                    )
                                },
                            )
                        })

                        if (window.Echo) {
                            window.dispatchEvent(new CustomEvent('EchoLoaded'))
                        }
                    </script>
                @endscript
            @endif

            @if ($isPaginated)
                <x-slot name="footer">
                    <x-filament::pagination :paginator="$notifications" />
                </x-slot>
            @endif
        @endif
    </x-filament::modal>
</div>

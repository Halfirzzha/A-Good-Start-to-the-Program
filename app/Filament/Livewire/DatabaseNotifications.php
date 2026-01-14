<?php

namespace App\Filament\Livewire;

use App\Support\AuthHelper;
use App\Support\NotificationCenterService;
use Filament\Livewire\DatabaseNotifications as BaseDatabaseNotifications;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class DatabaseNotifications extends BaseDatabaseNotifications
{
    public ?string $categoryFilter = null;

    public ?string $priorityFilter = null;

    public ?string $readFilter = null;

    /**
     * @return array<string, string>
     */
    public function getCategoryFilterOptions(): array
    {
        return NotificationCenterService::categoryOptions();
    }

    /**
     * @return array<string, string>
     */
    public function getPriorityFilterOptions(): array
    {
        return NotificationCenterService::priorityOptions();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage('database-notifications-page');
    }

    public function updatedPriorityFilter(): void
    {
        $this->resetPage('database-notifications-page');
    }

    public function updatedReadFilter(): void
    {
        $this->resetPage('database-notifications-page');
    }

    public function clearFilters(): void
    {
        $this->categoryFilter = null;
        $this->priorityFilter = null;
        $this->readFilter = null;
        $this->resetPage('database-notifications-page');
    }

    public function getNotificationsQuery(): Builder | Relation
    {
        $query = parent::getNotificationsQuery();
        $user = AuthHelper::user();

        if (! $user
            || (! $user->can('view_any_user_notification')
                && ! $user->can('view_user_notification')
                && ! $user->can('view_any_user_notifications')
                && ! $user->can('view_user_notifications'))) {
            return $query->whereRaw('1 = 0');
        }

        if (is_string($this->categoryFilter) && $this->categoryFilter !== '') {
            $query->where('data->category', $this->categoryFilter);
        }

        if (is_string($this->priorityFilter) && $this->priorityFilter !== '') {
            $query->where('data->priority', $this->priorityFilter);
        }

        if ($this->readFilter === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($this->readFilter === 'unread') {
            $query->whereNull('read_at');
        }

        return $query;
    }
}

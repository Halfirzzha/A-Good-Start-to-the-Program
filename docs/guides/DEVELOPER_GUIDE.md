# 👨‍💻 Panduan Developer - Creative Trees

<div align="center">

### Panduan Lengkap untuk Software Developer

[![Role](https://img.shields.io/badge/Role-Developer-purple?style=for-the-badge)](#)
[![Focus](https://img.shields.io/badge/Focus-Code%20%26%20Architecture-blue?style=for-the-badge)](#)

</div>

---

## 📋 Daftar Isi

1. [Architecture Overview](#-architecture-overview)
2. [Development Environment](#-development-environment)
3. [Directory Structure](#-directory-structure)
4. [Core Services](#-core-services)
5. [Filament Resources](#-filament-resources)
6. [Security Implementation](#-security-implementation)
7. [Audit System](#-audit-system)
8. [Testing](#-testing)
9. [Common Patterns](#-common-patterns)
10. [Troubleshooting](#-troubleshooting)

---

## 🏗️ Architecture Overview

### Technology Stack

| Component               | Technology           | Version    |
| ----------------------- | -------------------- | ---------- |
| **Framework**           | Laravel              | 12.x       |
| **Admin Panel**         | Filament             | 4.x        |
| **PHP**                 | PHP                  | 8.2+       |
| **Database**            | MySQL/MariaDB        | 8.0+/10.6+ |
| **Cache/Session/Queue** | Redis                | 6.0+       |
| **Frontend**            | Livewire + Alpine.js | 3.x        |
| **CSS**                 | Tailwind CSS         | 3.x        |
| **Build Tool**          | Vite                 | 5.x        |
| **Permission**          | Spatie Permission    | 6.x        |

### High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      CLIENT LAYER                            │
│  Browser → HTTP Request → Rate Limiting → CSP Headers       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                    MIDDLEWARE PIPELINE                       │
│  RequestId → Maintenance → Auth → Account → Stamp → Audit   │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     APPLICATION LAYER                        │
│  ┌───────────────┐ ┌───────────────┐ ┌───────────────┐     │
│  │   Filament    │ │   Policies    │ │   Services    │     │
│  │   Resources   │ │   & Gates     │ │   & Support   │     │
│  └───────────────┘ └───────────────┘ └───────────────┘     │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                       DATA LAYER                             │
│  ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐           │
│  │  MySQL  │ │  Redis  │ │  Redis  │ │  Redis  │           │
│  │   DB    │ │  Cache  │ │ Session │ │  Queue  │           │
│  └─────────┘ └─────────┘ └─────────┘ └─────────┘           │
└─────────────────────────────────────────────────────────────┘
```

### Request Lifecycle

```php
// Middleware execution order
1. RequestIdMiddleware        // X-Request-ID, CSP, Security Headers
2. MaintenanceModeMiddleware  // Maintenance gate check
3. Authenticate               // Laravel auth
4. EnsureAccountIsActive      // Account status validation
5. EnsureSecurityStampIsValid // Credential change detection
6. AuditLogMiddleware         // Request/response logging
```

---

## 🔧 Development Environment

### Initial Setup

```bash
# Clone repository
git clone <repository-url>
cd creative-trees

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure .env for development
# APP_DEBUG=true
# DB_DATABASE=creative_trees_dev

# Run migrations
php artisan migrate:fresh

# Seed initial data (development)
php artisan db:seed

# Generate Filament Shield permissions
php artisan shield:generate --all --panel=admin --option=permissions
php artisan permission:cache-reset

# Link storage
php artisan storage:link
```

### Development Server

```bash
# Option 1: All-in-one (Recommended)
composer dev

# This runs concurrently:
# - php artisan serve (HTTP server)
# - php artisan queue:listen (Queue worker)
# - php artisan pail (Log viewer)
# - npm run dev (Vite HMR)

# Option 2: Individual terminals
# Terminal 1: HTTP Server
php artisan serve

# Terminal 2: Queue Worker
php artisan queue:listen --tries=1

# Terminal 3: Vite (Hot Module Replacement)
npm run dev

# Terminal 4: Log Viewer (Optional)
php artisan pail --timeout=0
```

### IDE Setup (VS Code Recommended)

```json
// .vscode/settings.json
{
    "php.validate.executablePath": "/usr/local/bin/php",
    "editor.formatOnSave": true,
    "[php]": {
        "editor.defaultFormatter": "open-phpstorm.php-formatter"
    },
    "files.associations": {
        "*.blade.php": "blade"
    }
}
```

**Recommended Extensions:**

-   PHP Intelephense
-   Laravel Blade Snippets
-   Tailwind CSS IntelliSense
-   Alpine.js IntelliSense

---

## 📁 Directory Structure

```
app/
├── Console/
│   └── Commands/
│       ├── AuditVerifyCommand.php    # Verify audit hash chain
│       ├── AuditRehashCommand.php    # Rehash audit logs
│       └── AuditExportCommand.php    # Export audit logs
│
├── Enums/
│   ├── AccountStatus.php             # Active, Inactive, Suspended
│   └── UserRole.php                  # Role enum values
│
├── Filament/
│   ├── Auth/                         # Custom auth pages
│   │   ├── Login.php
│   │   ├── Register.php
│   │   └── PasswordReset.php
│   │
│   ├── Livewire/                     # Custom Livewire components
│   │   └── DatabaseNotifications.php # Bell dropdown
│   │
│   ├── Pages/                        # Custom pages
│   │   └── Dashboard.php
│   │
│   ├── Resources/                    # CRUD resources
│   │   ├── UserResource.php
│   │   ├── UserResource/
│   │   │   └── Pages/
│   │   ├── AuditLogResource.php
│   │   ├── MaintenanceSettingResource.php
│   │   ├── SystemSettingResource.php
│   │   └── ...
│   │
│   └── Widgets/                      # Dashboard widgets
│       ├── AccountWidget.php
│       └── FilamentInfoWidget.php
│
├── Http/
│   ├── Controllers/
│   │   ├── MaintenanceController.php
│   │   └── HealthCheckController.php
│   │
│   └── Middleware/
│       ├── RequestIdMiddleware.php
│       ├── EnsureAccountIsActive.php
│       ├── EnsureSecurityStampIsValid.php
│       └── AuditLogMiddleware.php
│
├── Jobs/
│   ├── SendSecurityAlert.php
│   └── SyncSettingsMediaToDrive.php
│
├── Listeners/
│   ├── RecordAuthActivity.php
│   ├── RecordNotificationSent.php
│   └── RecordNotificationFailed.php
│
├── Models/
│   ├── User.php                      # Main user model
│   ├── AuditLog.php                  # Audit log entries
│   ├── MaintenanceSetting.php
│   ├── MaintenanceToken.php
│   ├── NotificationMessage.php
│   ├── NotificationDelivery.php
│   ├── SystemSetting.php
│   └── Concerns/
│       └── Auditable.php             # Trait for audit logging
│
├── Notifications/
│   ├── QueuedResetPassword.php
│   ├── QueuedVerifyEmail.php
│   ├── SystemBroadcastNotification.php
│   └── UserInvitationNotification.php
│
├── Policies/
│   ├── UserPolicy.php
│   ├── AuditLogPolicy.php
│   ├── RolePolicy.php
│   └── SystemSettingPolicy.php
│
├── Providers/
│   ├── AppServiceProvider.php        # Core bindings, rate limits
│   └── AdminPanelProvider.php        # Filament configuration
│
├── Rules/
│   └── PasswordHistory.php           # Custom validation rules
│
└── Support/                          # Business logic services
    ├── AuditHasher.php               # Hash chain generation
    ├── AuditLogWriter.php            # Write audit entries
    ├── AuthHelper.php                # Authentication utilities
    ├── LocaleHelper.php              # Localization utilities
    ├── MaintenanceService.php        # Maintenance state management
    ├── MaintenanceTokenService.php   # Bypass token management
    ├── NotificationCenterService.php # Notification dispatch
    ├── NotificationDeliveryLogger.php
    ├── PasswordRules.php             # Password validation rules
    ├── SecurityAlert.php             # Alert generation
    ├── SettingsMediaStorage.php      # Media storage with fallback
    ├── SystemHealth.php              # Health check aggregation
    └── SystemSettings.php            # Dynamic settings cache
```

---

## 🔌 Core Services

### SystemSettings Service

Dynamic configuration management dengan Redis caching.

```php
use App\Support\SystemSettings;

// Get setting dengan default value
$siteName = SystemSettings::get('site_name', 'Creative Trees');

// Get dengan type casting
$maxUsers = SystemSettings::get('max_users', 100, 'integer');

// Set setting (requires permission)
SystemSettings::set('site_name', 'New Name');

// Get all settings
$all = SystemSettings::all();

// Clear cache
SystemSettings::clearCache();
```

**Lokasi:** `app/Support/SystemSettings.php`

### AuditLogWriter Service

Write tamper-evident audit logs dengan hash chain.

```php
use App\Support\AuditLogWriter;

// Manual audit log
AuditLogWriter::log(
    action: 'custom.action',
    model: $user,               // Auditable model
    changes: [
        'old' => ['status' => 'active'],
        'new' => ['status' => 'suspended'],
    ],
    metadata: [
        'reason' => 'Policy violation',
    ]
);

// Automatic logging via trait
class User extends Model
{
    use Auditable;

    protected array $auditExclude = ['password', 'remember_token'];
}
```

**Lokasi:** `app/Support/AuditLogWriter.php`

### MaintenanceService

Maintenance mode orchestration.

```php
use App\Support\MaintenanceService;

$service = app(MaintenanceService::class);

// Check status
$isActive = $service->isActive();
$status = $service->getStatus();  // Returns array with details

// Enable maintenance
$service->enable([
    'message' => 'Scheduled maintenance',
    'retry' => 300,  // Retry-After header value
]);

// Disable
$service->disable();

// Schedule maintenance
$service->schedule(
    startAt: now()->addHour(),
    endAt: now()->addHours(3),
    message: 'Database upgrade'
);
```

**Lokasi:** `app/Support/MaintenanceService.php`

### NotificationCenterService

Multi-channel notification dispatch.

```php
use App\Support\NotificationCenterService;

$service = app(NotificationCenterService::class);

// Send to specific users
$service->send(
    message: 'System update completed',
    title: 'Update Complete',
    users: User::whereRole('admin')->get(),
    channels: ['database', 'mail'],
    priority: 'high',
    category: 'announcement'
);

// Broadcast to all users
$service->broadcast(
    message: 'New feature available!',
    title: 'New Feature',
    category: 'announcement'
);
```

**Lokasi:** `app/Support/NotificationCenterService.php`

### AuthHelper

Authentication utilities.

```php
use App\Support\AuthHelper;

// Get current user (cached)
$user = AuthHelper::user();

// Check role
$isAdmin = AuthHelper::hasRole('admin');
$canManage = AuthHelper::hasAnyRole(['admin', 'super_admin']);

// Check hierarchy
$canManageUser = AuthHelper::canManage($targetUser);

// Get role level
$level = AuthHelper::getRoleLevel($user); // Returns int (10-100)
```

**Lokasi:** `app/Support/AuthHelper.php`

---

## 📊 Filament Resources

### Creating a New Resource

```bash
# Generate resource with all pages
php artisan make:filament-resource Project --generate

# Generate with soft deletes support
php artisan make:filament-resource Project --generate --soft-deletes

# Generate simple resource (modal-based, no pages)
php artisan make:filament-resource Tag --simple
```

### Resource Structure

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 10;

    // Navigation grouping
    public static function getNavigationGroup(): ?string
    {
        return __('ui.nav.groups.projects');
    }

    // Form schema
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('General')
                ->schema([
                    \Filament\Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    \Filament\Forms\Components\RichEditor::make('description')
                        ->columnSpanFull(),

                    \Filament\Forms\Components\Select::make('status')
                        ->options([
                            'draft' => 'Draft',
                            'active' => 'Active',
                            'completed' => 'Completed',
                        ])
                        ->required(),
                ]),
        ]);
    }

    // Table schema
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'gray' => 'draft',
                        'success' => 'active',
                        'primary' => 'completed',
                    ]),

                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'Draft',
                        'active' => 'Active',
                        'completed' => 'Completed',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'view' => Pages\ViewProject::route('/{record}'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
```

### Adding Shield Protection

```php
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use BezhanSalleh\FilamentShield\Traits\HasResourceShield;

// For Resources
class ProjectResource extends Resource
{
    // Shield automatically generates permissions:
    // view_any_project, view_project, create_project,
    // update_project, delete_project, etc.
}

// For Pages
class CustomPage extends Page
{
    use HasPageShield;

    // Generates: page_custom-page permission
}

// For Widgets
class StatsWidget extends Widget
{
    use HasWidgetShield;

    // Generates: widget_stats-widget permission
}
```

### Conditional Field Visibility

```php
use App\Support\AuthHelper;

// Based on role
\Filament\Forms\Components\TextInput::make('internal_notes')
    ->visible(fn () => AuthHelper::hasRole('admin')),

// Based on current record
\Filament\Forms\Components\Toggle::make('is_featured')
    ->visible(fn (?Model $record) => $record?->status === 'active'),

// Based on permission
\Filament\Forms\Components\Section::make('Admin Settings')
    ->visible(fn () => auth()->user()->can('manage_settings')),
```

---

## 🔒 Security Implementation

### Policy Implementation

```php
<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Support\AuthHelper;

class ProjectPolicy
{
    // View any record
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'user']);
    }

    // View specific record
    public function view(User $user, Project $project): bool
    {
        // Owner can always view
        if ($project->user_id === $user->id) {
            return true;
        }

        // Admins can view all
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    // Create new record
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    // Update record
    public function update(User $user, Project $project): bool
    {
        // Owner can update
        if ($project->user_id === $user->id) {
            return true;
        }

        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    // Delete record
    public function delete(User $user, Project $project): bool
    {
        // Cannot delete own project if not admin
        if ($project->user_id === $user->id) {
            return $user->hasRole('admin');
        }

        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    // Restore soft-deleted
    public function restore(User $user, Project $project): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    // Force delete
    public function forceDelete(User $user, Project $project): bool
    {
        return $user->hasRole('super_admin');
    }
}
```

### Role Hierarchy Check

```php
// In AuthHelper or Policy
public static function canManage(User $actor, User $target): bool
{
    $hierarchy = config('security.role_hierarchy');

    $actorLevel = 0;
    $targetLevel = 0;

    foreach ($actor->roles as $role) {
        $actorLevel = max($actorLevel, $hierarchy[$role->name] ?? 0);
    }

    foreach ($target->roles as $role) {
        $targetLevel = max($targetLevel, $hierarchy[$role->name] ?? 0);
    }

    // Actor must have higher level
    return $actorLevel > $targetLevel;
}
```

### Custom Middleware

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureProjectAccess
{
    public function handle(Request $request, Closure $next)
    {
        $project = $request->route('project');

        if (!$project) {
            return $next($request);
        }

        $user = $request->user();

        // Check if user can access this project
        if (!$user->can('view', $project)) {
            abort(403, 'You do not have access to this project.');
        }

        return $next($request);
    }
}
```

---

## 📝 Audit System

### How Hash Chain Works

```
┌──────────────────────────────────────────────────────────────┐
│ Log #1                                                        │
│ Data: {"action": "user.create", "user_id": 1}                │
│ Previous Hash: null (first entry)                            │
│ Hash: sha256(data + null) = "abc123..."                      │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ Log #2                                                        │
│ Data: {"action": "user.update", "user_id": 1}                │
│ Previous Hash: "abc123..."                                   │
│ Hash: sha256(data + "abc123...") = "def456..."               │
└──────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────────────────────────────────────────────┐
│ Log #3                                                        │
│ Data: {"action": "user.login", "user_id": 1}                 │
│ Previous Hash: "def456..."                                   │
│ Hash: sha256(data + "def456...") = "ghi789..."               │
└──────────────────────────────────────────────────────────────┘
```

### Implementing Auditable Trait

```php
<?php

namespace App\Models\Concerns;

use App\Support\AuditLogWriter;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            AuditLogWriter::log('created', $model, [
                'new' => $model->getAuditableAttributes(),
            ]);
        });

        static::updated(function ($model) {
            $changes = $model->getAuditableChanges();

            if (!empty($changes)) {
                AuditLogWriter::log('updated', $model, $changes);
            }
        });

        static::deleted(function ($model) {
            AuditLogWriter::log('deleted', $model, [
                'old' => $model->getAuditableAttributes(),
            ]);
        });
    }

    public function getAuditableAttributes(): array
    {
        $attributes = $this->getAttributes();
        $excluded = $this->auditExclude ?? [];

        return array_diff_key($attributes, array_flip($excluded));
    }

    public function getAuditableChanges(): array
    {
        $original = $this->getOriginal();
        $current = $this->getAttributes();
        $excluded = $this->auditExclude ?? [];

        $changes = ['old' => [], 'new' => []];

        foreach ($current as $key => $value) {
            if (in_array($key, $excluded)) continue;

            if (($original[$key] ?? null) !== $value) {
                $changes['old'][$key] = $original[$key] ?? null;
                $changes['new'][$key] = $value;
            }
        }

        return empty($changes['old']) ? [] : $changes;
    }
}
```

### Verifying Audit Integrity

```bash
# Verify all audit logs
php artisan audit:verify

# Verify specific date range
php artisan audit:verify --from="2026-01-01" --to="2026-01-14"

# Verbose output
php artisan audit:verify -v
```

### Rehashing Audit Logs

```bash
# Rehash if secret changed (requires confirmation)
php artisan audit:rehash --new-secret="new-secret-key"
```

---

## 🧪 Testing

### Test Structure

```
tests/
├── TestCase.php              # Base test class
├── Feature/
│   ├── Auth/
│   │   ├── LoginTest.php
│   │   └── PasswordResetTest.php
│   ├── User/
│   │   ├── CreateUserTest.php
│   │   ├── UpdateUserTest.php
│   │   └── DeleteUserTest.php
│   └── Audit/
│       └── AuditLogTest.php
└── Unit/
    ├── Services/
    │   ├── AuditHasherTest.php
    │   └── SystemSettingsTest.php
    └── Models/
        └── UserTest.php
```

### Writing Feature Tests

```php
<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'New User',
                'email' => 'newuser@example.com',
                'username' => 'newuser',
                'password' => 'SecurePass123!',
                'password_confirmation' => 'SecurePass123!',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
        ]);
    }

    public function test_manager_cannot_create_admin(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)
            ->post('/admin/users', [
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'username' => 'adminuser',
                'password' => 'SecurePass123!',
                'role' => 'admin',  // Should be rejected
            ])
            ->assertForbidden();
    }
}
```

### Writing Unit Tests

```php
<?php

namespace Tests\Unit\Services;

use App\Support\AuditHasher;
use PHPUnit\Framework\TestCase;

class AuditHasherTest extends TestCase
{
    public function test_generates_consistent_hash(): void
    {
        $data = ['action' => 'test', 'user_id' => 1];
        $previousHash = 'abc123';

        $hash1 = AuditHasher::generate($data, $previousHash);
        $hash2 = AuditHasher::generate($data, $previousHash);

        $this->assertEquals($hash1, $hash2);
    }

    public function test_different_data_produces_different_hash(): void
    {
        $data1 = ['action' => 'test1'];
        $data2 = ['action' => 'test2'];

        $hash1 = AuditHasher::generate($data1, null);
        $hash2 = AuditHasher::generate($data2, null);

        $this->assertNotEquals($hash1, $hash2);
    }
}
```

### Running Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test tests/Feature/User/CreateUserTest.php

# Run with coverage
php artisan test --coverage

# Run in parallel
php artisan test --parallel

# Filter by name
php artisan test --filter=test_admin_can_create_user
```

---

## 🔄 Common Patterns

### Repository Pattern (Optional)

```php
<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function __construct(
        protected User $model
    ) {}

    public function findById(int $id): ?User
    {
        return $this->model->find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function getActive(): Collection
    {
        return $this->model
            ->where('account_status', 'active')
            ->orderBy('name')
            ->get();
    }

    public function create(array $data): User
    {
        return $this->model->create($data);
    }
}
```

### Action Pattern

```php
<?php

namespace App\Actions\User;

use App\Models\User;
use App\Support\AuditLogWriter;
use Illuminate\Support\Facades\Hash;

class CreateUser
{
    public function execute(array $data, User $actor): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            'password' => Hash::make($data['password']),
            'account_status' => 'active',
            'created_by_admin_id' => $actor->id,
        ]);

        if (isset($data['role'])) {
            $user->assignRole($data['role']);
        }

        return $user;
    }
}

// Usage in Controller/Resource
$action = new CreateUser();
$user = $action->execute($validated, auth()->user());
```

### Service Pattern

```php
<?php

namespace App\Services;

use App\Models\User;
use App\Jobs\SendWelcomeEmail;
use App\Notifications\UserCreatedNotification;

class UserService
{
    public function create(array $data): User
    {
        $user = User::create($data);

        // Dispatch jobs
        SendWelcomeEmail::dispatch($user);

        // Send notification to admins
        User::role('admin')->each(function ($admin) use ($user) {
            $admin->notify(new UserCreatedNotification($user));
        });

        return $user;
    }

    public function suspend(User $user, string $reason, ?int $minutes = null): void
    {
        $user->update([
            'account_status' => 'suspended',
            'blocked_reason' => $reason,
            'blocked_until' => $minutes ? now()->addMinutes($minutes) : null,
        ]);

        // Invalidate all sessions
        $user->update(['security_stamp' => \Str::random(40)]);
    }
}
```

---

## 🔧 Troubleshooting

### Common Issues

#### Cache Issues

```bash
# Clear all caches
php artisan optimize:clear

# Specific caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Reset permission cache
php artisan permission:cache-reset
```

#### Queue Issues

```bash
# Check queue status
php artisan queue:monitor

# Retry failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush

# Restart workers (after code changes)
php artisan queue:restart
```

#### Database Issues

```bash
# Check migration status
php artisan migrate:status

# Rollback and re-migrate
php artisan migrate:fresh

# Run specific migration
php artisan migrate --path=database/migrations/2026_01_14_000000_create_xxx_table.php
```

### Debugging Tips

```php
// Log debugging
\Log::debug('Debug message', ['context' => $data]);

// Dump and die (development only)
dd($variable);
dump($variable);

// Query logging
\DB::enableQueryLog();
// ... run queries ...
dd(\DB::getQueryLog());

// Telescope (if installed)
// Visit /telescope for request/query/log inspection
```

### Performance Profiling

```bash
# Check slow queries (via observability config)
# OBSERVABILITY_SLOW_QUERY_MS=500

# Tail logs for slow requests
tail -f storage/logs/laravel.log | grep "slow_request"

# Check Redis memory
redis-cli info memory
```

---

## 📚 Additional Resources

### Official Documentation

-   [Laravel 12 Documentation](https://laravel.com/docs/12.x)
-   [Filament v4 Documentation](https://filamentphp.com/docs)
-   [Livewire 3 Documentation](https://livewire.laravel.com/docs)
-   [Spatie Permission](https://spatie.be/docs/laravel-permission)

### Internal Documentation

-   [LEARNING_CURVE.md](../LEARNING_CURVE.md) - Main learning guide
-   [SECURITY_AUDIT_GUIDE.md](SECURITY_AUDIT_GUIDE.md) - Security documentation
-   [QUICK_REFERENCE.md](QUICK_REFERENCE.md) - Quick command reference

---

<div align="center">

**Happy Coding! 🚀**

</div>

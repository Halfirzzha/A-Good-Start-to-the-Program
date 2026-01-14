# 📋 Quick Reference Guide - Creative Trees

<div align="center">

### Referensi Cepat untuk Operasi Sehari-hari

[![Type](https://img.shields.io/badge/Type-Cheat%20Sheet-blue?style=for-the-badge)](#)

</div>

---

## 📑 Daftar Isi Cepat

-   [Artisan Commands](#-artisan-commands)
-   [Composer Scripts](#-composer-scripts)
-   [Configuration Keys](#-configuration-keys)
-   [Role & Permissions](#-role--permissions)
-   [API Endpoints](#-api-endpoints)
-   [Keyboard Shortcuts](#-keyboard-shortcuts)
-   [Troubleshooting Quick Fixes](#-troubleshooting-quick-fixes)

---

## 🛠️ Artisan Commands

### Application

| Command                      | Description              |
| ---------------------------- | ------------------------ |
| `php artisan serve`          | Start development server |
| `php artisan optimize:clear` | Clear all caches         |
| `php artisan config:cache`   | Cache configuration      |
| `php artisan route:cache`    | Cache routes             |
| `php artisan view:cache`     | Cache views              |
| `php artisan storage:link`   | Create storage symlink   |

### Database

| Command                        | Description             |
| ------------------------------ | ----------------------- |
| `php artisan migrate`          | Run pending migrations  |
| `php artisan migrate:fresh`    | Drop all & re-migrate   |
| `php artisan migrate:rollback` | Rollback last migration |
| `php artisan migrate:status`   | Show migration status   |
| `php artisan db:seed`          | Run database seeders    |

### Queue

| Command                       | Description            |
| ----------------------------- | ---------------------- |
| `php artisan queue:work`      | Start queue worker     |
| `php artisan queue:listen`    | Listen for jobs (dev)  |
| `php artisan queue:restart`   | Restart queue workers  |
| `php artisan queue:retry all` | Retry all failed jobs  |
| `php artisan queue:flush`     | Delete all failed jobs |

### Filament / Shield

| Command                                | Description            |
| -------------------------------------- | ---------------------- |
| `php artisan make:filament-user`       | Create admin user      |
| `php artisan make:filament-resource X` | Create new resource    |
| `php artisan shield:generate --all`    | Generate permissions   |
| `php artisan permission:cache-reset`   | Reset permission cache |

### Audit

| Command                             | Description            |
| ----------------------------------- | ---------------------- |
| `php artisan audit:verify`          | Verify audit integrity |
| `php artisan audit:rehash`          | Rehash audit chain     |
| `php artisan audit:export --days=7` | Export recent logs     |

### Maintenance

| Command                           | Description         |
| --------------------------------- | ------------------- |
| `php artisan down`                | Enable maintenance  |
| `php artisan down --secret=token` | Enable with bypass  |
| `php artisan up`                  | Disable maintenance |

---

## 📦 Composer Scripts

| Script           | Command           | Description                  |
| ---------------- | ----------------- | ---------------------------- |
| `composer dev`   | Multiple services | Server + Queue + Logs + Vite |
| `composer setup` | Initial setup     | Install + Migrate + Build    |
| `composer test`  | Run tests         | PHPUnit test suite           |

---

## ⚙️ Configuration Keys

### Environment Variables

```bash
# Application
APP_NAME="Creative Trees"
APP_ENV=local|production
APP_DEBUG=true|false
APP_URL=https://your-domain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=database_name
DB_USERNAME=root
DB_PASSWORD=

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Queue & Cache
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Security
SECURITY_ALERT_ENABLED=true
SECURITY_DEVELOPER_BYPASS_VALIDATIONS=false
SECURITY_LOCKOUT_ATTEMPTS=5
SECURITY_PASSWORD_MIN_LENGTH=12

# Audit
AUDIT_LOG_ENABLED=true
AUDIT_SIGNATURE_ENABLED=true
AUDIT_SIGNATURE_SECRET=your-secret

# Observability
OBSERVABILITY_SLOW_REQUEST_MS=800
OBSERVABILITY_SLOW_QUERY_MS=500
```

### Config File Locations

| Config        | File                       | Purpose              |
| ------------- | -------------------------- | -------------------- |
| Security      | `config/security.php`      | Security settings    |
| Audit         | `config/audit.php`         | Audit logging        |
| Observability | `config/observability.php` | Performance tracking |
| Services      | `config/services.php`      | Third-party services |

---

## 👥 Role & Permissions

### Role Hierarchy

| Role        | Level | Description              |
| ----------- | :---: | ------------------------ |
| Developer   |  100  | Full access + bypasses   |
| Super Admin |  90   | Full access, no bypasses |
| Admin       |  80   | User management          |
| Manager     |  70   | Limited user view        |
| User        |  10   | Self-service only        |

### Permission Format

```
resource:action

Examples:
user:view-any
user:create
user:update
user:delete
audit-log:view-any
system-setting:update
```

### Quick Permission Check

```php
// In code
$user->hasRole('admin');
$user->hasAnyRole(['admin', 'super_admin']);
$user->can('user:create');
$user->cannot('user:force-delete');

// In Blade
@role('admin') ... @endrole
@can('user:create') ... @endcan
```

---

## 🌐 API Endpoints

### Public Endpoints

| Method | Endpoint              | Description          |
| ------ | --------------------- | -------------------- |
| GET    | `/health/check`       | System health status |
| GET    | `/maintenance/status` | Maintenance status   |
| POST   | `/maintenance/bypass` | Bypass with token    |

### Admin Panel Routes

| Route                         | Description     |
| ----------------------------- | --------------- |
| `/admin`                      | Dashboard       |
| `/admin/login`                | Login page      |
| `/admin/users`                | User management |
| `/admin/audit-logs`           | Audit logs      |
| `/admin/notifications`        | Notifications   |
| `/admin/maintenance-settings` | Maintenance     |
| `/admin/system-settings`      | System settings |

---

## ⌨️ Keyboard Shortcuts

### Filament Panel

| Shortcut       | Action        |
| -------------- | ------------- |
| `Ctrl/Cmd + K` | Global search |
| `Esc`          | Close modal   |
| `Enter`        | Submit form   |

### Table Actions

| Shortcut           | Action          |
| ------------------ | --------------- |
| `Ctrl/Cmd + Click` | Select multiple |
| `Shift + Click`    | Select range    |

---

## 🔧 Troubleshooting Quick Fixes

### "Class not found" Error

```bash
composer dump-autoload
php artisan optimize:clear
```

### Permission Denied

```bash
php artisan permission:cache-reset
php artisan cache:clear
```

### Session Issues

```bash
php artisan session:clear
# or
redis-cli FLUSHDB  # if using Redis
```

### Styles Not Loading

```bash
npm run build
php artisan view:clear
```

### Queue Not Processing

```bash
php artisan queue:restart
php artisan queue:work --tries=3
```

### 500 Error

```bash
tail -f storage/logs/laravel.log
# Check the error message
php artisan optimize:clear
```

### Login Not Working

```bash
# Check account status
php artisan tinker
>>> User::where('email', 'email@example.com')->first()->account_status

# Reset password
>>> User::where('email', 'email@example.com')->update(['password' => Hash::make('NewPass123!')])
```

### Rate Limited (429)

```bash
# Wait 1 minute, or
redis-cli DEL "laravel_rate_limiter:*"
```

---

## 📊 Status Codes Quick Reference

| Code | Meaning          | Action             |
| :--: | ---------------- | ------------------ |
| 200  | Success          | -                  |
| 201  | Created          | -                  |
| 302  | Redirect         | Follow redirect    |
| 401  | Unauthorized     | Login required     |
| 403  | Forbidden        | Check permissions  |
| 404  | Not Found        | Check URL          |
| 419  | CSRF Mismatch    | Refresh page       |
| 422  | Validation Error | Check form data    |
| 429  | Rate Limited     | Wait and retry     |
| 500  | Server Error     | Check logs         |
| 503  | Maintenance      | Wait or use bypass |

---

## 🔐 Security Quick Checks

### Pre-Deployment

```bash
# Check debug mode
grep "APP_DEBUG" .env  # Should be false

# Check developer bypass
grep "BYPASS_VALIDATIONS" .env  # Should be false

# Verify audit
php artisan audit:verify

# Run tests
php artisan test
```

### Production Essentials

```env
APP_ENV=production
APP_DEBUG=false
SECURITY_DEVELOPER_BYPASS_VALIDATIONS=false
SECURITY_ALERT_ENABLED=true
AUDIT_SIGNATURE_ENABLED=true
SESSION_SECURE_COOKIE=true
```

---

## 📁 Important File Locations

| Type       | Location                              |
| ---------- | ------------------------------------- |
| Logs       | `storage/logs/laravel.log`            |
| Sessions   | Redis or `storage/framework/sessions` |
| Cache      | Redis or `storage/framework/cache`    |
| Uploads    | `storage/app/public`                  |
| Config     | `config/*.php`                        |
| Migrations | `database/migrations`                 |
| Models     | `app/Models`                          |
| Resources  | `app/Filament/Resources`              |
| Policies   | `app/Policies`                        |
| Services   | `app/Support`                         |
| Middleware | `app/Http/Middleware`                 |

---

## 🔗 Useful Links

| Resource          | URL                                       |
| ----------------- | ----------------------------------------- |
| Laravel Docs      | https://laravel.com/docs                  |
| Filament Docs     | https://filamentphp.com/docs              |
| Livewire Docs     | https://livewire.laravel.com              |
| Spatie Permission | https://spatie.be/docs/laravel-permission |

---

## 📞 Quick Contacts

| Issue             | Action                      |
| ----------------- | --------------------------- |
| Security Incident | Email: security@company.com |
| System Down       | Check logs → Contact DevOps |
| Permission Issue  | Contact Super Admin         |
| Bug Report        | Create GitHub Issue         |

---

<div align="center">

**Keep this guide handy for quick reference!**

_Print-friendly version available_

</div>

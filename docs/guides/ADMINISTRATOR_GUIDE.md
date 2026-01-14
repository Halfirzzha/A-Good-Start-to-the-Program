# 👔 Panduan Administrator - Creative Trees

<div align="center">

### Panduan Lengkap untuk System Administrator

[![Role](https://img.shields.io/badge/Role-Admin%20%2F%20Super%20Admin-blue?style=for-the-badge)](#)
[![Focus](https://img.shields.io/badge/Focus-Operations%20%26%20Management-green?style=for-the-badge)](#)

</div>

---

## 📋 Daftar Isi

1. [Pengantar Role Administrator](#-pengantar-role-administrator)
2. [Akses Pertama Kali](#-akses-pertama-kali)
3. [Manajemen Pengguna](#-manajemen-pengguna)
4. [Manajemen Role & Permissions](#-manajemen-role--permissions)
5. [Konfigurasi Sistem](#-konfigurasi-sistem)
6. [Maintenance Mode](#-maintenance-mode)
7. [Notification Center](#-notification-center)
8. [Monitoring & Audit](#-monitoring--audit)
9. [Best Practices](#-best-practices)
10. [FAQ Administrator](#-faq-administrator)

---

## 👤 Pengantar Role Administrator

### Perbedaan Admin vs Super Admin

| Kemampuan                           |       Admin        |   Super Admin   |
| ----------------------------------- | :----------------: | :-------------: |
| Manage Users (Create, Edit, Delete) |         ✅         |       ✅        |
| Assign Roles ke User                | ⚠️ Manager & below |  ✅ All roles   |
| View Audit Logs                     |         ✅         |       ✅        |
| Export Audit Logs                   |         ❌         |       ✅        |
| System Settings                     |     ⚠️ Limited     |     ✅ Full     |
| Maintenance Mode                    |    ⚠️ View only    | ✅ Full control |
| Manage Roles/Permissions            |         ❌         |       ✅        |
| Delete Other Admins                 |         ❌         |       ✅        |
| View Security Alerts                |         ✅         |       ✅        |

### Tanggung Jawab Utama

1. **User Lifecycle Management**

    - Onboarding pengguna baru
    - Role assignment
    - Account deactivation
    - Password reset management

2. **System Operations**

    - Scheduled maintenance
    - System configuration
    - Performance monitoring

3. **Security Oversight**
    - Audit log review
    - Security alert response
    - Access control management

---

## 🚀 Akses Pertama Kali

### Login ke Admin Panel

1. Buka browser dan navigasi ke `https://your-domain.com/admin`
2. Masukkan email dan password yang diberikan
3. Jika diminta, verifikasi email terlebih dahulu
4. Setelah login, Anda akan diarahkan ke Dashboard

### Dashboard Overview

```
┌─────────────────────────────────────────────────────┐
│                    DASHBOARD                         │
├─────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────────────┐   │
│  │  Account Widget │  │  Quick Stats            │   │
│  │  - Your Profile │  │  - Total Users: XX      │   │
│  │  - Role: Admin  │  │  - Active Today: XX     │   │
│  │  - Last Login   │  │  - Pending Invites: X   │   │
│  └─────────────────┘  └─────────────────────────┘   │
│                                                      │
│  ┌─────────────────────────────────────────────┐    │
│  │           Recent Activity                    │    │
│  │  • User John created - 2 min ago            │    │
│  │  • Settings updated - 1 hour ago            │    │
│  │  • Login from new IP - 3 hours ago          │    │
│  └─────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

### Navigasi Menu

| Menu          | Deskripsi                         | Akses Admin |
| ------------- | --------------------------------- | :---------: |
| Dashboard     | Overview sistem                   |     ✅      |
| Users         | Manajemen pengguna                |     ✅      |
| Roles         | Manajemen role (Super Admin only) |     ⚠️      |
| Audit Logs    | Log aktivitas sistem              |     ✅      |
| Notifications | Pusat notifikasi                  |     ✅      |
| Maintenance   | Pengaturan maintenance            |     ⚠️      |
| Settings      | Konfigurasi sistem                |     ⚠️      |

---

## 👥 Manajemen Pengguna

### Membuat User Baru

#### Langkah-langkah:

1. **Navigasi ke Users**

    - Klik menu "Users" di sidebar
    - Klik tombol "Create" (+ New User)

2. **Isi Informasi Dasar**

    ```
    Tab: Main Information
    ├── Avatar (Optional)
    ├── Name * (Nama lengkap)
    ├── Email * (Email valid)
    ├── Username * (Unique, untuk login)
    └── Position (Jabatan)
    ```

3. **Set Credentials**

    ```
    Tab: Security
    ├── Password * (Min 12 karakter)
    ├── Password Confirmation *
    ├── Must Change Password (Force reset on login)
    └── Two Factor Auth (Optional)
    ```

4. **Assign Role**

    ```
    Tab: Role & Permissions
    ├── Role * (User/Manager/Admin)
    └── Additional Permissions (Optional)
    ```

5. **Klik "Create"**

#### Password Requirements

| Requirement  | Minimum                        |
| ------------ | ------------------------------ |
| Length       | 12 characters                  |
| Uppercase    | Required                       |
| Lowercase    | Required                       |
| Numbers      | Required                       |
| Symbols      | Required                       |
| Not Breached | Checked against HaveIBeenPwned |

### Mengedit User

1. Navigasi ke Users → klik nama user
2. Klik tombol "Edit"
3. Ubah informasi yang diperlukan
4. Klik "Save Changes"

> **⚠️ Penting:** Mengubah email atau password akan menginvalidasi semua sesi aktif user tersebut.

### Menonaktifkan User

#### Temporary Block (Suspend)

```
1. Buka user profile
2. Scroll ke section "Account Status"
3. Ubah status ke "Suspended"
4. Isi alasan (Required)
5. Set durasi block (Optional)
6. Save
```

#### Permanent Deactivation

```
1. Buka user profile
2. Ubah status ke "Inactive"
3. Isi alasan
4. Save
```

> **💡 Tip:** Inactive users tidak dapat login tetapi data mereka tetap tersimpan untuk keperluan audit.

### Menghapus User

#### Soft Delete (Recommended)

```
1. Buka user profile
2. Klik "Delete" di header
3. Konfirmasi dengan mengetik username
4. Klik "Delete"
```

User yang di-soft delete:

-   ❌ Tidak bisa login
-   ✅ Data audit tetap ada
-   ✅ Dapat di-restore

#### Permanent Delete (Super Admin Only)

```
1. Navigasi ke Users → Filter "Trashed"
2. Pilih user yang sudah di-delete
3. Klik "Force Delete"
4. Konfirmasi (TIDAK DAPAT DI-UNDO)
```

### Bulk Operations

| Operation       | Cara                                |
| --------------- | ----------------------------------- |
| Select Multiple | Checkbox di sebelah kiri            |
| Bulk Delete     | Select → Actions → Delete Selected  |
| Bulk Suspend    | Select → Actions → Suspend Selected |
| Export List     | Actions → Export → CSV/Excel        |

---

## 🔐 Manajemen Role & Permissions

> **⚠️ Section ini hanya untuk Super Admin**

### Hierarki Role Default

```
Level 100: Developer     ─┬─ Full system access
Level 90:  Super Admin   ─┤  All admin features, no dev bypass
Level 80:  Admin         ─┤  User management, limited settings
Level 70:  Manager       ─┤  View users, limited actions
Level 10:  User          ─┘  Self-service only
```

### Melihat Permissions

1. Navigasi ke Roles (di bawah Security group)
2. Klik role yang ingin dilihat
3. Review permissions yang terdaftar

### Permission Naming Convention

```
resource_name:action

Contoh:
- user:view-any      (Lihat daftar user)
- user:create        (Buat user baru)
- user:update        (Edit user)
- user:delete        (Hapus user)
- user:restore       (Restore user yang dihapus)
- user:force-delete  (Hapus permanen)
```

### Membuat Role Baru

```bash
# Via Artisan Command
php artisan shield:generate --all
```

Atau via UI:

1. Roles → Create
2. Masukkan nama role
3. Pilih permissions
4. Save

### Assign Role ke User

1. Buka user profile
2. Tab "Role & Permissions"
3. Select role dari dropdown
4. Save

---

## ⚙️ Konfigurasi Sistem

### System Settings

Akses: Settings → System Settings

| Setting          | Deskripsi          | Default        |
| ---------------- | ------------------ | -------------- |
| Site Name        | Nama aplikasi      | Creative Trees |
| Site Description | Deskripsi singkat  | -              |
| Logo             | Logo aplikasi      | -              |
| Favicon          | Icon browser tab   | -              |
| Timezone         | Zona waktu default | UTC            |
| Locale           | Bahasa default     | en             |

### Security Settings

| Setting          | Deskripsi                          | Recommended     |
| ---------------- | ---------------------------------- | --------------- |
| Password Expiry  | Hari sebelum password expired      | 90              |
| Lockout Attempts | Percobaan login gagal sebelum lock | 5               |
| Lockout Duration | Durasi lock dalam menit            | 15              |
| Session Lifetime | Durasi session dalam menit         | 120             |
| Force 2FA        | Wajibkan Two-Factor Auth           | Production: Yes |

### Email Settings

| Setting      | Deskripsi               |
| ------------ | ----------------------- |
| Mail Driver  | SMTP, Mailgun, SES, etc |
| SMTP Host    | smtp.example.com        |
| SMTP Port    | 587 (TLS) / 465 (SSL)   |
| From Address | noreply@yourdomain.com  |
| From Name    | Creative Trees          |

---

## 🛠️ Maintenance Mode

### Kapan Menggunakan Maintenance Mode

-   ✅ Scheduled updates
-   ✅ Database migrations
-   ✅ Server maintenance
-   ✅ Security patches
-   ❌ **BUKAN** untuk menyelesaikan bugs (gunakan hotfix)

### Mengaktifkan Maintenance Mode

#### Scheduled Maintenance (Recommended)

1. Navigasi ke Maintenance → Settings
2. Klik "Schedule Maintenance"
3. Isi form:
    ```
    Start Time: 2026-01-20 02:00:00
    End Time:   2026-01-20 04:00:00
    Message:    "Scheduled maintenance for database upgrade"
    Allow IPs:  [optional - whitelist IPs]
    ```
4. Klik "Schedule"

Users akan melihat countdown sebelum maintenance dimulai.

#### Immediate Maintenance (Emergency Only)

1. Navigasi ke Maintenance → Settings
2. Klik "Enable Now"
3. Konfirmasi

### Bypass Tokens

Bypass token memungkinkan user tertentu mengakses sistem saat maintenance.

#### Generate Bypass Token

1. Maintenance → Tokens
2. Klik "Generate New Token"
3. Pilih user yang akan diberikan akses
4. Set expiry (default: end of maintenance)
5. Klik "Generate"

Token akan dikirim via email ke user tersebut.

#### Revoke Token

1. Maintenance → Tokens
2. Cari token yang ingin direvoke
3. Klik "Revoke"
4. Konfirmasi

### Monitoring During Maintenance

```
Maintenance Status Dashboard:
┌─────────────────────────────────────┐
│ Status: MAINTENANCE MODE ACTIVE     │
│ Started: 2026-01-20 02:00:00        │
│ Scheduled End: 2026-01-20 04:00:00  │
├─────────────────────────────────────┤
│ Active Bypass Tokens: 3             │
│ Connected Admins: 2                 │
│ Pending Queue Jobs: 45              │
└─────────────────────────────────────┘
```

### Menonaktifkan Maintenance Mode

1. Maintenance → Settings
2. Klik "End Maintenance"
3. Konfirmasi

Semua user akan bisa akses kembali, tokens akan di-invalidate.

---

## 📬 Notification Center

### Membuat Notification Message

1. Navigasi ke Notifications → Messages
2. Klik "Create"
3. Isi form:
    ```
    Title:     "System Update Completed"
    Body:      "The scheduled maintenance has been completed..."
    Category:  Announcement
    Priority:  Normal
    Target:    All Users / Specific Roles / Specific Users
    Channels:  [x] In-App  [x] Email  [ ] Push
    ```
4. Klik "Send Now" atau "Schedule"

### Notification Categories

| Category     | Penggunaan       | Icon |
| ------------ | ---------------- | ---- |
| Announcement | Pengumuman umum  | 📢   |
| Security     | Alert keamanan   | 🔒   |
| Maintenance  | Info maintenance | 🛠️   |
| Update       | System updates   | ⬆️   |
| Reminder     | Pengingat        | ⏰   |

### Targeting Options

```
All Users          → Semua user aktif
By Role            → Manager, Admin, etc.
Specific Users     → Pilih user tertentu
By Department      → Jika ada custom field
Exclude            → Kecuali user tertentu
```

### Monitoring Delivery

1. Notifications → Deliveries
2. Filter by message, status, atau date
3. Check delivery status:
    - ✅ Delivered
    - ⏳ Pending
    - ❌ Failed
    - 📖 Read

### Notification Templates

Simpan template untuk penggunaan berulang:

1. Messages → Templates
2. Create template
3. Saat membuat notification, pilih template

---

## 📊 Monitoring & Audit

### Audit Log Access

Navigasi ke Security → Audit Logs

### Filtering Audit Logs

| Filter     | Options                             |
| ---------- | ----------------------------------- |
| User       | Specific user atau "System"         |
| Action     | Create, Update, Delete, Login, etc. |
| Resource   | User, Setting, Notification, etc.   |
| Date Range | From - To                           |
| IP Address | Specific IP                         |
| Status     | Success / Failed                    |

### Reading Audit Entries

```
┌─────────────────────────────────────────────────────┐
│ AUDIT LOG ENTRY #12345                              │
├─────────────────────────────────────────────────────┤
│ Timestamp:  2026-01-14 10:30:45 UTC                │
│ User:       admin@example.com                       │
│ IP Address: 192.168.1.100                          │
│ User Agent: Chrome/120.0 (Windows)                  │
│ Action:     user.update                            │
│ Resource:   User #42                               │
│ Request ID: req_abc123xyz                          │
├─────────────────────────────────────────────────────┤
│ Changes:                                            │
│   role: "user" → "manager"                         │
│   position: null → "Team Lead"                     │
├─────────────────────────────────────────────────────┤
│ Hash Chain: ✓ Valid                                │
│ Signature:  ✓ Valid                                │
└─────────────────────────────────────────────────────┘
```

### Verifikasi Integritas Audit

> **Super Admin Only**

```
1. Audit Logs → Actions → Verify Integrity
2. System akan check seluruh hash chain
3. Report akan ditampilkan:
   - Total logs checked
   - Valid entries
   - Invalid entries (jika ada)
```

### Export Audit Logs

1. Audit Logs → Actions → Export
2. Pilih format (CSV, Excel, JSON)
3. Pilih range tanggal
4. Pilih fields yang akan di-export
5. Download

### Security Alerts

Navigasi ke Security → Alerts

| Alert Type         | Severity | Action Required   |
| ------------------ | -------- | ----------------- |
| Failed Login Spike | High     | Investigate IP    |
| Suspicious Pattern | High     | Review & Block    |
| Rate Limit Hit     | Medium   | Monitor           |
| New Admin Created  | Medium   | Verify legitimate |
| Password Changed   | Low      | Informational     |

---

## ✅ Best Practices

### User Management

1. **Principle of Least Privilege**

    - Berikan role serendah mungkin yang masih memungkinkan user bekerja
    - Hindari membuat terlalu banyak Super Admin

2. **Regular Review**

    - Review user list bulanan
    - Deactivate inactive users
    - Audit role assignments

3. **Onboarding Checklist**

    ```
    [ ] Create user account
    [ ] Assign appropriate role
    [ ] Send welcome email
    [ ] Verify email confirmed
    [ ] Schedule training if needed
    ```

4. **Offboarding Checklist**
    ```
    [ ] Revoke all active sessions
    [ ] Deactivate account
    [ ] Review recent activity
    [ ] Transfer ownership of assets
    [ ] Archive or delete after retention period
    ```

### Security

1. **Password Management**

    - Jangan share password
    - Use password manager
    - Enable 2FA untuk semua admin

2. **Session Security**

    - Logout setelah selesai
    - Jangan login dari public computer
    - Monitor active sessions

3. **Alert Response**
    - Review security alerts daily
    - Investigate high-severity alerts immediately
    - Document incident response

### Maintenance

1. **Planning**

    - Schedule during low-traffic hours
    - Notify users in advance (min 24 hours)
    - Have rollback plan

2. **Execution**

    - Use bypass tokens sparingly
    - Monitor during maintenance
    - Test before ending maintenance

3. **Post-Maintenance**
    - Verify all systems operational
    - Check for errors in logs
    - Send completion notification

---

## ❓ FAQ Administrator

### Q: Bagaimana cara reset password user tanpa mengetahui password lama?

**A:**

1. Buka user profile
2. Tab Security → Klik "Reset Password"
3. Set password baru atau generate random
4. Enable "Must Change Password" agar user set password mereka sendiri

### Q: User tidak bisa login, apa yang harus dilakukan?

**A:** Cek secara berurutan:

1. Account Status - Pastikan "Active"
2. Email Verified - Pastikan terverifikasi
3. Blocked Until - Pastikan tidak dalam periode block
4. Recent Login Attempts - Cek jika terkena rate limit
5. Password Expired - Cek expiry date

### Q: Bagaimana cara melihat siapa yang mengubah data tertentu?

**A:**

1. Audit Logs → Filter by Resource
2. Pilih resource type dan ID
3. Review history perubahan

### Q: Apakah bisa membatalkan perubahan yang sudah disimpan?

**A:** Sistem tidak memiliki fitur "undo" otomatis, tetapi:

1. Lihat di Audit Log untuk nilai sebelumnya
2. Manual edit kembali ke nilai lama
3. Atau restore dari backup (koordinasi dengan Developer)

### Q: Bagaimana cara menangani security alert?

**A:**

1. Baca detail alert
2. Identifikasi source (IP, User)
3. Jika legitimate - dismiss alert
4. Jika suspicious - block IP/user
5. Document di incident log

### Q: Maintenance mode tidak bisa dimatikan, apa yang salah?

**A:** Kemungkinan:

1. Cache issue - Minta developer clear cache
2. Database lock - Check database status
3. Config cached - `php artisan config:clear`

---

<div align="center">

## 📞 Kontak Support

Jika mengalami masalah yang tidak tercakup dalam panduan ini:

| Channel       | Detail                 |
| ------------- | ---------------------- |
| Email         | support@yourdomain.com |
| Slack         | #admin-support         |
| Documentation | [Internal Wiki](#)     |

**⏰ Response Time:** Critical (1 jam), High (4 jam), Normal (24 jam)

</div>

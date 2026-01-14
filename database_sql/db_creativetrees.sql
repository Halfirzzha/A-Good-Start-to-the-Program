-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jan 12, 2026 at 02:25 AM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `db_creativetrees`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_name` varchar(191) DEFAULT NULL,
  `user_email` varchar(191) DEFAULT NULL,
  `user_username` varchar(100) DEFAULT NULL,
  `role_name` varchar(100) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `auditable_type` varchar(255) DEFAULT NULL,
  `auditable_id` bigint(20) UNSIGNED DEFAULT NULL,
  `auditable_label` varchar(191) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `user_agent_hash` varchar(64) DEFAULT NULL,
  `url` text DEFAULT NULL,
  `route` varchar(255) DEFAULT NULL,
  `method` varchar(10) DEFAULT NULL,
  `status_code` smallint(5) UNSIGNED DEFAULT NULL,
  `request_id` varchar(36) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `duration_ms` int(10) UNSIGNED DEFAULT NULL,
  `request_referer` text DEFAULT NULL,
  `request_payload_hash` varchar(64) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `hash` varchar(64) DEFAULT NULL,
  `previous_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `user_name`, `user_email`, `user_username`, `role_name`, `action`, `auditable_type`, `auditable_id`, `auditable_label`, `old_values`, `new_values`, `ip_address`, `user_agent`, `user_agent_hash`, `url`, `route`, `method`, `status_code`, `request_id`, `session_id`, `duration_ms`, `request_referer`, `request_payload_hash`, `context`, `hash`, `previous_hash`, `created_at`) VALUES
(1, NULL, NULL, NULL, NULL, NULL, 'permissions_bootstrap', 'Spatie\\Permission\\Models\\Role', 1, NULL, NULL, NULL, '127.0.0.1', 'Symfony', '45eff1960e31c7dec75830614cf50de9bad832e964f8074a92567fcb9aecdd05', 'http://127.0.0.1:8000', NULL, 'GET', NULL, NULL, NULL, NULL, NULL, '94095c174418f2a39e8aea2056a7b24560fca7f68fa032affacadb0ba963561c', '{\"role\":\"developer\",\"guard\":\"web\",\"permissions_created\":[\"access_admin_panel\",\"assign_roles\",\"execute_user_unlock\",\"execute_user_activate\",\"execute_user_force_password_reset\",\"execute_user_revoke_sessions\",\"manage_user_avatar\",\"manage_user_identity\",\"manage_user_security\",\"execute_maintenance_bypass_token\",\"execute_notification_send\",\"execute_unified_history_create\",\"manage_system_setting_secrets\",\"manage_system_setting_project_url\"],\"permissions_assigned\":[\"access_admin_panel\",\"assign_roles\",\"execute_maintenance_bypass_token\",\"execute_notification_send\",\"execute_unified_history_create\",\"execute_user_activate\",\"execute_user_force_password_reset\",\"execute_user_revoke_sessions\",\"execute_user_unlock\",\"manage_maintenance_access\",\"manage_maintenance_message\",\"manage_maintenance_schedule\",\"manage_system_setting_project_url\",\"manage_system_setting_secrets\",\"manage_system_settings_branding\",\"manage_system_settings_communication\",\"manage_system_settings_project\",\"manage_system_settings_storage\",\"manage_user_access_status\",\"manage_user_avatar\",\"manage_user_identity\",\"manage_user_security\",\"view_user_system_info\"]}', 'dbed879b4963aaf3bcf541a27849b1260bcf41c45aa69c942a0e75c2e9c813d0', NULL, '2026-01-11 17:58:55'),
(2, NULL, NULL, NULL, NULL, NULL, 'created', 'App\\Models\\User', 1, 'M\'HALFIRZZHATULLAH', NULL, '{\"name\":\"M\'HALFIRZZHATULLAH\",\"email\":\"halfirzzha@gmail.com\",\"password\":\"[redacted]\",\"uuid\":\"6a6cec4f-71c1-4f08-8c54-f20ad7752fc5\",\"security_stamp\":\"[redacted]\",\"updated_at\":\"[redacted]\",\"created_at\":\"[redacted]\",\"id\":1}', '127.0.0.1', 'Symfony', '45eff1960e31c7dec75830614cf50de9bad832e964f8074a92567fcb9aecdd05', 'http://127.0.0.1:8000', NULL, 'GET', NULL, 'e7bcc266-1fa9-432f-a0ec-142e9b787185', '', NULL, NULL, '94095c174418f2a39e8aea2056a7b24560fca7f68fa032affacadb0ba963561c', '{\"source\":\"console\"}', 'b7154b12e8c91690d61bbec7ba6974ebbc31f3686e070cddd866a4e1fc48c702', 'dbed879b4963aaf3bcf541a27849b1260bcf41c45aa69c942a0e75c2e9c813d0', '2026-01-11 18:24:46'),
(3, NULL, NULL, NULL, NULL, NULL, 'updated', 'App\\Models\\User', 1, 'M\'HALFIRZZHATULLAH', '[]', '{\"name\":\"M\'HALFIRZZHATULLAH\",\"email\":\"halfirzzha@gmail.com\",\"password\":\"[redacted]\",\"uuid\":\"6a6cec4f-71c1-4f08-8c54-f20ad7752fc5\",\"security_stamp\":\"[redacted]\",\"updated_at\":\"[redacted]\",\"created_at\":\"[redacted]\",\"id\":1,\"username\":\"halfirzzha\",\"password_changed_at\":\"2026-01-12 01:24:46\",\"password_expires_at\":\"2026-04-12 01:24:46\"}', '127.0.0.1', 'Symfony', '45eff1960e31c7dec75830614cf50de9bad832e964f8074a92567fcb9aecdd05', 'http://127.0.0.1:8000', NULL, 'GET', NULL, 'bb3edfdd-a0b9-4d22-9895-820c28892172', '', NULL, NULL, '94095c174418f2a39e8aea2056a7b24560fca7f68fa032affacadb0ba963561c', '{\"source\":\"console\"}', '04a46a0f2b32967757818da5b4b7487743a6ad135999a70a0145b43797c7e074', 'b7154b12e8c91690d61bbec7ba6974ebbc31f3686e070cddd866a4e1fc48c702', '2026-01-11 18:24:46'),
(4, NULL, NULL, NULL, NULL, NULL, 'updated', 'App\\Models\\User', 1, 'M\'HALFIRZZHATULLAH', '[]', '{\"role\":\"developer\"}', '127.0.0.1', 'Symfony', '45eff1960e31c7dec75830614cf50de9bad832e964f8074a92567fcb9aecdd05', 'http://127.0.0.1:8000', NULL, 'GET', NULL, '90f5c6f5-3eae-419d-8191-d886104974a5', '', NULL, NULL, '94095c174418f2a39e8aea2056a7b24560fca7f68fa032affacadb0ba963561c', '{\"source\":\"console\"}', '291c8c6e756d633014312e04afa69d237f7052a272f86f0c899175457fec3dfb', '04a46a0f2b32967757818da5b4b7487743a6ad135999a70a0145b43797c7e074', '2026-01-11 18:24:46'),
(5, NULL, NULL, NULL, NULL, NULL, 'updated', 'App\\Models\\User', 1, 'M\'HALFIRZZHATULLAH', '[]', '{\"email_verified_at\":\"2026-01-12 01:24:46\"}', '127.0.0.1', 'Symfony', '45eff1960e31c7dec75830614cf50de9bad832e964f8074a92567fcb9aecdd05', 'http://127.0.0.1:8000', NULL, 'GET', NULL, '0544c725-3a03-427e-894d-aac17475d8f8', '', NULL, NULL, '94095c174418f2a39e8aea2056a7b24560fca7f68fa032affacadb0ba963561c', '{\"source\":\"console\"}', '1f0508e5b9a5b194d4677600b5fe06b1da3b3afb443153910f0316806da2d6ea', '291c8c6e756d633014312e04afa69d237f7052a272f86f0c899175457fec3dfb', '2026-01-11 18:24:46');

-- --------------------------------------------------------

--
-- Table structure for table `cache`
--

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_locks`
--

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_batches`
--

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_settings`
--

CREATE TABLE `maintenance_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `mode` varchar(20) NOT NULL DEFAULT 'global',
  `title` varchar(160) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `note_html` longtext DEFAULT NULL,
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `retry_after` int(10) UNSIGNED DEFAULT NULL,
  `allow_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allow_roles`)),
  `allow_ips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allow_ips`)),
  `allow_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allow_paths`)),
  `deny_paths` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deny_paths`)),
  `allow_routes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allow_routes`)),
  `deny_routes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`deny_routes`)),
  `allow_api` tinyint(1) NOT NULL DEFAULT 0,
  `allow_developer_bypass` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_ip` varchar(45) DEFAULT NULL,
  `updated_user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `maintenance_settings`
--

INSERT INTO `maintenance_settings` (`id`, `enabled`, `mode`, `title`, `summary`, `note_html`, `start_at`, `end_at`, `retry_after`, `allow_roles`, `allow_ips`, `allow_paths`, `deny_paths`, `allow_routes`, `deny_routes`, `allow_api`, `allow_developer_bypass`, `updated_by`, `updated_ip`, `updated_user_agent`, `created_at`, `updated_at`) VALUES
(1, 0, 'global', NULL, NULL, NULL, NULL, NULL, NULL, '[]', '[]', '[]', '[]', '[]', '[]', 0, 0, NULL, NULL, NULL, '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(2, 0, 'global', NULL, NULL, NULL, NULL, NULL, NULL, '[]', '[]', '[]', '[]', '[]', '[]', 0, 0, NULL, NULL, NULL, '2026-01-11 17:58:55', '2026-01-11 17:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_tokens`
--

CREATE TABLE `maintenance_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `token_hash` varchar(255) NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2025_12_26_162441_create_permission_tables', 1),
(5, '2025_12_26_200000_create_user_password_histories_table', 1),
(6, '2025_12_27_000000_create_system_settings_tables', 1),
(7, '2025_12_27_000001_create_user_invitations_table', 1),
(8, '2026_01_05_000000_create_notifications_table', 1),
(9, '2026_01_05_000010_create_notification_deliveries_table', 1),
(10, '2026_01_10_000000_create_maintenance_tables', 1),
(11, '2026_01_10_010000_create_notification_messages_table', 1);

-- --------------------------------------------------------

--
-- Table structure for table `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `model_has_roles`
--

INSERT INTO `model_has_roles` (`role_id`, `model_type`, `model_id`) VALUES
(1, 'App\\Models\\User', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint(20) UNSIGNED NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_channels`
--

CREATE TABLE `notification_channels` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(40) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `provider` varchar(60) DEFAULT NULL,
  `from_name` varchar(120) DEFAULT NULL,
  `from_address` varchar(200) DEFAULT NULL,
  `reply_to` varchar(200) DEFAULT NULL,
  `chat_id` varchar(120) DEFAULT NULL,
  `sms_sender` varchar(120) DEFAULT NULL,
  `max_attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 3,
  `retry_after_seconds` smallint(5) UNSIGNED NOT NULL DEFAULT 60,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_deliveries`
--

CREATE TABLE `notification_deliveries` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_id` bigint(20) UNSIGNED DEFAULT NULL,
  `notification_type` varchar(200) NOT NULL,
  `channel` varchar(40) NOT NULL,
  `status` varchar(20) NOT NULL,
  `attempts` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `notifiable_type` varchar(255) DEFAULT NULL,
  `notifiable_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recipient` varchar(200) DEFAULT NULL,
  `idempotency_key` varchar(100) DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `summary` varchar(250) DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `error_message` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `device_type` varchar(20) DEFAULT NULL,
  `request_id` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_messages`
--

CREATE TABLE `notification_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `category` varchar(30) NOT NULL,
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `target_all` tinyint(1) NOT NULL DEFAULT 0,
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_targets`
--

CREATE TABLE `notification_targets` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `target_type` varchar(30) NOT NULL,
  `target_value` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'access_admin_panel', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(2, 'assign_roles', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(3, 'execute_user_unlock', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(4, 'execute_user_activate', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(5, 'execute_user_force_password_reset', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(7, 'execute_user_revoke_sessions', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(8, 'manage_user_avatar', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(9, 'manage_user_identity', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(11, 'manage_user_security', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(12, 'manage_user_access_status', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(14, 'view_user_system_info', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(15, 'execute_maintenance_bypass_token', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(16, 'execute_notification_send', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(18, 'execute_unified_history_create', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(19, 'manage_system_setting_secrets', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(21, 'manage_system_setting_project_url', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(22, 'manage_system_settings_project', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(24, 'manage_system_settings_branding', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(26, 'manage_system_settings_storage', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(27, 'manage_system_settings_communication', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(29, 'manage_maintenance_schedule', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(30, 'manage_maintenance_message', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(32, 'manage_maintenance_access', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `guard_name`, `created_at`, `updated_at`) VALUES
(1, 'developer', 'web', '2026-01-11 17:58:55', '2026-01-11 17:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_has_permissions`
--

INSERT INTO `role_has_permissions` (`permission_id`, `role_id`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(5, 1),
(7, 1),
(8, 1),
(9, 1),
(11, 1),
(12, 1),
(14, 1),
(15, 1),
(16, 1),
(18, 1),
(19, 1),
(21, 1),
(22, 1),
(24, 1),
(26, 1),
(27, 1),
(29, 1),
(30, 1),
(32, 1);

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_name` varchar(120) NOT NULL DEFAULT 'System',
  `project_description` text DEFAULT NULL,
  `project_url` varchar(191) DEFAULT NULL,
  `branding_logo_disk` varchar(50) DEFAULT NULL,
  `branding_logo_path` varchar(255) DEFAULT NULL,
  `branding_logo_fallback_disk` varchar(50) DEFAULT NULL,
  `branding_logo_fallback_path` varchar(255) DEFAULT NULL,
  `branding_logo_status` varchar(50) NOT NULL DEFAULT 'unset',
  `branding_logo_updated_at` timestamp NULL DEFAULT NULL,
  `branding_cover_disk` varchar(50) DEFAULT NULL,
  `branding_cover_path` varchar(255) DEFAULT NULL,
  `branding_cover_fallback_disk` varchar(50) DEFAULT NULL,
  `branding_cover_fallback_path` varchar(255) DEFAULT NULL,
  `branding_cover_status` varchar(50) NOT NULL DEFAULT 'unset',
  `branding_cover_updated_at` timestamp NULL DEFAULT NULL,
  `branding_favicon_disk` varchar(50) DEFAULT NULL,
  `branding_favicon_path` varchar(255) DEFAULT NULL,
  `branding_favicon_fallback_disk` varchar(50) DEFAULT NULL,
  `branding_favicon_fallback_path` varchar(255) DEFAULT NULL,
  `branding_favicon_status` varchar(50) NOT NULL DEFAULT 'unset',
  `branding_favicon_updated_at` timestamp NULL DEFAULT NULL,
  `storage_primary_disk` varchar(50) NOT NULL DEFAULT 'google',
  `storage_fallback_disk` varchar(50) NOT NULL DEFAULT 'public',
  `storage_drive_root` varchar(120) NOT NULL DEFAULT 'Warex-System',
  `storage_drive_folder_branding` varchar(120) NOT NULL DEFAULT 'branding',
  `storage_drive_folder_favicon` varchar(120) NOT NULL DEFAULT 'branding',
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email_provider` varchar(100) NOT NULL DEFAULT 'SMTP',
  `email_from_name` varchar(120) DEFAULT NULL,
  `email_from_address` varchar(191) DEFAULT NULL,
  `email_auth_from_name` varchar(120) DEFAULT NULL,
  `email_auth_from_address` varchar(191) DEFAULT NULL,
  `email_recipients` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`email_recipients`)),
  `smtp_mailer` varchar(20) NOT NULL DEFAULT 'smtp',
  `smtp_host` varchar(191) DEFAULT NULL,
  `smtp_port` smallint(5) UNSIGNED NOT NULL DEFAULT 587,
  `smtp_encryption` varchar(10) NOT NULL DEFAULT 'tls',
  `smtp_username` varchar(191) DEFAULT NULL,
  `smtp_password` longtext DEFAULT NULL,
  `telegram_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `telegram_chat_id` varchar(50) DEFAULT NULL,
  `telegram_bot_token` longtext DEFAULT NULL,
  `google_drive_service_account_json` longtext DEFAULT NULL,
  `google_drive_client_id` varchar(191) DEFAULT NULL,
  `google_drive_client_secret` varchar(191) DEFAULT NULL,
  `google_drive_refresh_token` varchar(191) DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_ip` varchar(45) DEFAULT NULL,
  `updated_user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `project_name`, `project_description`, `project_url`, `branding_logo_disk`, `branding_logo_path`, `branding_logo_fallback_disk`, `branding_logo_fallback_path`, `branding_logo_status`, `branding_logo_updated_at`, `branding_cover_disk`, `branding_cover_path`, `branding_cover_fallback_disk`, `branding_cover_fallback_path`, `branding_cover_status`, `branding_cover_updated_at`, `branding_favicon_disk`, `branding_favicon_path`, `branding_favicon_fallback_disk`, `branding_favicon_fallback_path`, `branding_favicon_status`, `branding_favicon_updated_at`, `storage_primary_disk`, `storage_fallback_disk`, `storage_drive_root`, `storage_drive_folder_branding`, `storage_drive_folder_favicon`, `email_enabled`, `email_provider`, `email_from_name`, `email_from_address`, `email_auth_from_name`, `email_auth_from_address`, `email_recipients`, `smtp_mailer`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_username`, `smtp_password`, `telegram_enabled`, `telegram_chat_id`, `telegram_bot_token`, `google_drive_service_account_json`, `google_drive_client_id`, `google_drive_client_secret`, `google_drive_refresh_token`, `updated_by`, `updated_ip`, `updated_user_agent`, `created_at`, `updated_at`) VALUES
(1, 'Warex Management System', '', 'http://127.0.0.1:8000', NULL, NULL, NULL, NULL, 'unset', NULL, NULL, NULL, NULL, NULL, 'unset', NULL, NULL, NULL, NULL, NULL, 'unset', NULL, 'google', 'public', 'Warex-System', 'branding', 'branding', 1, 'SMTP', NULL, NULL, NULL, NULL, '[]', 'smtp', NULL, 587, 'tls', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-11 17:58:55', '2026-01-11 17:58:55'),
(2, 'Warex Management System', '', 'http://127.0.0.1:8000', NULL, NULL, NULL, NULL, 'unset', NULL, NULL, NULL, NULL, NULL, 'unset', NULL, NULL, NULL, NULL, NULL, 'unset', NULL, 'google', 'public', 'Warex-System', 'branding', 'branding', 1, 'SMTP', NULL, NULL, NULL, NULL, '[]', 'smtp', NULL, 587, 'tls', NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '2026-01-11 17:58:55', '2026-01-11 17:58:55');

-- --------------------------------------------------------

--
-- Table structure for table `system_setting_versions`
--

CREATE TABLE `system_setting_versions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `system_setting_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(50) NOT NULL DEFAULT 'updated',
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot`)),
  `changed_keys` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changed_keys`)),
  `actor_id` bigint(20) UNSIGNED DEFAULT NULL,
  `request_id` varchar(36) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `name` varchar(255) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'user',
  `phone_country_code` varchar(5) DEFAULT '+62',
  `phone_number` varchar(20) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `locale` varchar(10) DEFAULT NULL,
  `security_stamp` varchar(64) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_changed_at` timestamp NULL DEFAULT NULL,
  `password_changed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `password_expires_at` timestamp NULL DEFAULT NULL,
  `last_password_changed_ip` varchar(45) DEFAULT NULL,
  `last_password_changed_user_agent` varchar(255) DEFAULT NULL,
  `first_login_at` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `last_login_user_agent` varchar(255) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_ip` varchar(45) DEFAULT NULL,
  `failed_login_attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_failed_login_at` timestamp NULL DEFAULT NULL,
  `last_failed_login_ip` varchar(45) DEFAULT NULL,
  `last_failed_login_user_agent` varchar(255) DEFAULT NULL,
  `locked_at` timestamp NULL DEFAULT NULL,
  `blocked_until` timestamp NULL DEFAULT NULL,
  `account_status` enum('active','blocked','suspended','terminated') NOT NULL DEFAULT 'active',
  `blocked_reason` text DEFAULT NULL,
  `blocked_by` bigint(20) UNSIGNED DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_method` varchar(20) DEFAULT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_by_type` enum('system','admin') NOT NULL DEFAULT 'system',
  `created_by_admin_id` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `deleted_ip` varchar(45) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `uuid`, `name`, `username`, `email`, `email_verified_at`, `password`, `avatar`, `position`, `role`, `phone_country_code`, `phone_number`, `timezone`, `locale`, `security_stamp`, `must_change_password`, `password_changed_at`, `password_changed_by`, `password_expires_at`, `last_password_changed_ip`, `last_password_changed_user_agent`, `first_login_at`, `last_login_at`, `last_login_ip`, `last_login_user_agent`, `last_seen_at`, `last_seen_ip`, `failed_login_attempts`, `last_failed_login_at`, `last_failed_login_ip`, `last_failed_login_user_agent`, `locked_at`, `blocked_until`, `account_status`, `blocked_reason`, `blocked_by`, `two_factor_enabled`, `two_factor_method`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `created_by_type`, `created_by_admin_id`, `deleted_by`, `deleted_ip`, `remember_token`, `created_at`, `updated_at`, `deleted_at`) VALUES
(1, '6a6cec4f-71c1-4f08-8c54-f20ad7752fc5', 'M\'HALFIRZZHATULLAH', 'halfirzzha', 'halfirzzha@gmail.com', '2026-01-11 18:24:46', '$2y$12$DawZ2K3PiTrlPlQZYhT0i.6YtTF2Uk6y.nq.M/Rgk90f/jylbhObO', NULL, NULL, 'developer', '+62', NULL, NULL, NULL, '46DHDSleMPgyimU50bbT2QUDV8uDLYPhHUILCaWZNGZ8O3oraTLXFnwSAbAYF9d8', 0, '2026-01-11 18:24:46', NULL, '2026-04-11 18:24:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 'active', NULL, NULL, 0, NULL, NULL, NULL, NULL, 'system', NULL, NULL, NULL, NULL, '2026-01-11 18:24:46', '2026-01-11 18:24:46', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_invitations`
--

CREATE TABLE `user_invitations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_login_activities`
--

CREATE TABLE `user_login_activities` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `identity` varchar(191) DEFAULT NULL,
  `event` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `session_id` varchar(100) DEFAULT NULL,
  `request_id` varchar(36) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notifications`
--

CREATE TABLE `user_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `notification_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `channel` varchar(32) NOT NULL DEFAULT 'inapp',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_password_histories`
--

CREATE TABLE `user_password_histories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_password_histories`
--

INSERT INTO `user_password_histories` (`id`, `user_id`, `password`, `created_at`) VALUES
(1, 1, '$2y$12$DawZ2K3PiTrlPlQZYhT0i.6YtTF2Uk6y.nq.M/Rgk90f/jylbhObO', '2026-01-11 18:24:46');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `audit_logs_user_created_idx` (`user_id`,`created_at`),
  ADD KEY `audit_logs_user_email_idx` (`user_email`),
  ADD KEY `audit_logs_user_username_idx` (`user_username`),
  ADD KEY `audit_logs_role_created_idx` (`role_name`,`created_at`),
  ADD KEY `audit_logs_action_created_idx` (`action`,`created_at`),
  ADD KEY `audit_logs_auditable_idx` (`auditable_type`,`auditable_id`),
  ADD KEY `audit_logs_auditable_label_idx` (`auditable_label`),
  ADD KEY `audit_logs_request_id_idx` (`request_id`),
  ADD KEY `audit_logs_session_id_idx` (`session_id`),
  ADD KEY `audit_logs_status_code_idx` (`status_code`),
  ADD KEY `audit_logs_ip_address_idx` (`ip_address`),
  ADD KEY `audit_logs_user_agent_hash_idx` (`user_agent_hash`),
  ADD KEY `audit_logs_request_payload_hash_idx` (`request_payload_hash`),
  ADD KEY `audit_logs_hash_index` (`hash`),
  ADD KEY `audit_logs_previous_hash_index` (`previous_hash`);

--
-- Indexes for table `cache`
--
ALTER TABLE `cache`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `cache_locks`
--
ALTER TABLE `cache_locks`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indexes for table `job_batches`
--
ALTER TABLE `job_batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance_settings`
--
ALTER TABLE `maintenance_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `maintenance_settings_enabled_idx` (`enabled`),
  ADD KEY `maintenance_settings_window_idx` (`start_at`,`end_at`),
  ADD KEY `maintenance_settings_updated_by_idx` (`updated_by`);

--
-- Indexes for table `maintenance_tokens`
--
ALTER TABLE `maintenance_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `maintenance_tokens_revoked_at_expires_at_index` (`revoked_at`,`expires_at`),
  ADD KEY `maintenance_tokens_created_by_idx` (`created_by`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`);

--
-- Indexes for table `notification_channels`
--
ALTER TABLE `notification_channels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notification_channels_notification_idx` (`notification_id`,`channel`);

--
-- Indexes for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notification_deliveries_notifiable_type_notifiable_id_index` (`notifiable_type`,`notifiable_id`),
  ADD KEY `notification_deliveries_channel_status_index` (`channel`,`status`),
  ADD KEY `notification_deliveries_notification_idx` (`notification_id`,`channel`,`status`);

--
-- Indexes for table `notification_messages`
--
ALTER TABLE `notification_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notification_messages_status_schedule_idx` (`status`,`scheduled_at`),
  ADD KEY `notification_messages_category_priority_idx` (`category`,`priority`),
  ADD KEY `notification_messages_created_by_foreign` (`created_by`),
  ADD KEY `notification_messages_updated_by_foreign` (`updated_by`),
  ADD KEY `notification_messages_category_index` (`category`),
  ADD KEY `notification_messages_priority_index` (`priority`),
  ADD KEY `notification_messages_status_index` (`status`),
  ADD KEY `notification_messages_scheduled_at_index` (`scheduled_at`),
  ADD KEY `notification_messages_expires_at_index` (`expires_at`);

--
-- Indexes for table `notification_targets`
--
ALTER TABLE `notification_targets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `notification_targets_notification_idx` (`notification_id`,`target_type`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`),
  ADD KEY `password_reset_tokens_created_at_index` (`created_at`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indexes for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sessions_user_id_index` (`user_id`),
  ADD KEY `sessions_last_activity_index` (`last_activity`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `system_settings_updated_by_idx` (`updated_by`),
  ADD KEY `system_settings_project_name_idx` (`project_name`),
  ADD KEY `system_settings_project_url_idx` (`project_url`),
  ADD KEY `system_settings_storage_primary_idx` (`storage_primary_disk`),
  ADD KEY `system_settings_storage_fallback_idx` (`storage_fallback_disk`),
  ADD KEY `system_settings_email_enabled_idx` (`email_enabled`),
  ADD KEY `system_settings_email_provider_idx` (`email_provider`),
  ADD KEY `system_settings_smtp_host_idx` (`smtp_host`),
  ADD KEY `system_settings_telegram_enabled_idx` (`telegram_enabled`),
  ADD KEY `system_settings_smtp_username_idx` (`smtp_username`),
  ADD KEY `system_settings_email_from_address_idx` (`email_from_address`),
  ADD KEY `system_settings_email_auth_from_address_idx` (`email_auth_from_address`);

--
-- Indexes for table `system_setting_versions`
--
ALTER TABLE `system_setting_versions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `system_setting_versions_setting_idx` (`system_setting_id`),
  ADD KEY `system_setting_versions_actor_idx` (`actor_id`),
  ADD KEY `system_setting_versions_action_created_idx` (`action`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_uuid_unique` (`uuid`),
  ADD UNIQUE KEY `users_email_unique` (`email`),
  ADD UNIQUE KEY `users_username_unique` (`username`),
  ADD KEY `users_username_index` (`username`),
  ADD KEY `users_phone_number_index` (`phone_number`),
  ADD KEY `users_role_index` (`role`),
  ADD KEY `users_account_status_index` (`account_status`),
  ADD KEY `users_last_login_at_index` (`last_login_at`),
  ADD KEY `users_locked_at_index` (`locked_at`),
  ADD KEY `users_email_account_status_index` (`email`,`account_status`),
  ADD KEY `users_deleted_at_index` (`deleted_at`),
  ADD KEY `users_password_changed_by_foreign` (`password_changed_by`),
  ADD KEY `users_blocked_by_foreign` (`blocked_by`),
  ADD KEY `users_created_by_admin_id_foreign` (`created_by_admin_id`),
  ADD KEY `users_deleted_by_foreign` (`deleted_by`);

--
-- Indexes for table `user_invitations`
--
ALTER TABLE `user_invitations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_invitations_token_hash_unique` (`token_hash`),
  ADD KEY `user_invitations_user_used_idx` (`user_id`,`used_at`),
  ADD KEY `user_invitations_expires_idx` (`expires_at`),
  ADD KEY `user_invitations_created_by_fk` (`created_by`);

--
-- Indexes for table `user_login_activities`
--
ALTER TABLE `user_login_activities`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_login_activities_event_created_at_index` (`event`,`created_at`),
  ADD KEY `user_login_activities_user_id_index` (`user_id`);

--
-- Indexes for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_notifications_unique` (`notification_id`,`user_id`),
  ADD KEY `user_notifications_user_read_idx` (`user_id`,`is_read`),
  ADD KEY `user_notifications_is_read_index` (`is_read`);

--
-- Indexes for table `user_password_histories`
--
ALTER TABLE `user_password_histories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_password_histories_user_id_index` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_settings`
--
ALTER TABLE `maintenance_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `maintenance_tokens`
--
ALTER TABLE `maintenance_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `notification_channels`
--
ALTER TABLE `notification_channels`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_deliveries`
--
ALTER TABLE `notification_deliveries`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_messages`
--
ALTER TABLE `notification_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_targets`
--
ALTER TABLE `notification_targets`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `system_setting_versions`
--
ALTER TABLE `system_setting_versions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `user_invitations`
--
ALTER TABLE `user_invitations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_login_activities`
--
ALTER TABLE `user_login_activities`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_notifications`
--
ALTER TABLE `user_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_password_histories`
--
ALTER TABLE `user_password_histories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance_settings`
--
ALTER TABLE `maintenance_settings`
  ADD CONSTRAINT `maintenance_settings_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `maintenance_tokens`
--
ALTER TABLE `maintenance_tokens`
  ADD CONSTRAINT `maintenance_tokens_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_channels`
--
ALTER TABLE `notification_channels`
  ADD CONSTRAINT `notification_channels_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notification_messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_messages`
--
ALTER TABLE `notification_messages`
  ADD CONSTRAINT `notification_messages_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `notification_messages_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notification_targets`
--
ALTER TABLE `notification_targets`
  ADD CONSTRAINT `notification_targets_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notification_messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD CONSTRAINT `system_settings_updated_by_fk` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `system_setting_versions`
--
ALTER TABLE `system_setting_versions`
  ADD CONSTRAINT `system_setting_versions_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `system_setting_versions_setting_fk` FOREIGN KEY (`system_setting_id`) REFERENCES `system_settings` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_blocked_by_foreign` FOREIGN KEY (`blocked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_created_by_admin_id_foreign` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_deleted_by_foreign` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_password_changed_by_foreign` FOREIGN KEY (`password_changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_invitations`
--
ALTER TABLE `user_invitations`
  ADD CONSTRAINT `user_invitations_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `user_invitations_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_login_activities`
--
ALTER TABLE `user_login_activities`
  ADD CONSTRAINT `user_login_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_notifications`
--
ALTER TABLE `user_notifications`
  ADD CONSTRAINT `user_notifications_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `notification_messages` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_password_histories`
--
ALTER TABLE `user_password_histories`
  ADD CONSTRAINT `user_password_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

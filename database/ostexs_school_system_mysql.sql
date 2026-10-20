-- OSTEXS.COM School Result Management System
-- Target: MySQL 5.7+ / MariaDB 10.3+
-- Generated for CPanel deployment
-- Regenerated to match the actual Laravel migrations (app/database/migrations)
-- exactly, column-for-column - see /database/migrations/*.php as the source
-- of truth. Default account: username "headmaster" / password "admin123"
-- (must_change_password is set, so it will be forced to change on first login).

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
START TRANSACTION;
SET time_zone = '+03:00';
SET NAMES utf8mb4;

-- --------------------------------------------------------
-- Table structure for `classes`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `standard` int(11) DEFAULT NULL,
  `stream` varchar(50) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `teacher_id` bigint(20) UNSIGNED DEFAULT NULL,
  `last_promoted_year` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `classes_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `users`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` varchar(191) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(191) NOT NULL,
  `role` varchar(50) NOT NULL,
  `class_id` bigint(20) UNSIGNED DEFAULT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `must_change_password` tinyint(4) NOT NULL DEFAULT 0,
  `photo_path` varchar(255) DEFAULT NULL,
  `title` varchar(100) DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` varchar(50) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_unique` (`username`),
  KEY `users_class_id_foreign` (`class_id`),
  CONSTRAINT `users_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add the classes.teacher_id foreign key now that `users` exists
-- (mirrors migration 2026_01_01_000002_create_users_table.php)
ALTER TABLE `classes`
  ADD CONSTRAINT `classes_teacher_id_foreign` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Table structure for `subjects`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` varchar(191) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `class_subjects`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `class_subjects`;
CREATE TABLE `class_subjects` (
  `class_id` bigint(20) UNSIGNED NOT NULL,
  `subject_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`class_id`, `subject_id`),
  KEY `class_subjects_subject_id_foreign` (`subject_id`),
  CONSTRAINT `class_subjects_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_subjects_subject_id_foreign` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `students`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `reg_no` varchar(191) NOT NULL,
  `full_name` varchar(191) NOT NULL,
  `gender` varchar(20) NOT NULL DEFAULT 'Unknown',
  `gender_confirmed` tinyint(4) NOT NULL DEFAULT 0,
  `class_id` bigint(20) UNSIGNED NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `leave_reason` text DEFAULT NULL,
  `created_at` varchar(50) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_reg_no_unique` (`reg_no`),
  KEY `students_class_id_foreign` (`class_id`),
  CONSTRAINT `students_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `examinations`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `examinations`;
CREATE TABLE `examinations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_type` varchar(100) NOT NULL,
  `academic_year` varchar(50) NOT NULL,
  `created_at` varchar(50) NOT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `examinations_exam_type_academic_year_unique` (`exam_type`, `academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `marks`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `marks`;
CREATE TABLE `marks` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `student_id` bigint(20) UNSIGNED NOT NULL,
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `subject_id` bigint(20) UNSIGNED NOT NULL,
  `class_id` bigint(20) UNSIGNED DEFAULT NULL,
  `score` double DEFAULT NULL,
  `entered_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `marks_student_id_exam_id_subject_id_unique` (`student_id`, `exam_id`, `subject_id`),
  KEY `marks_exam_id_foreign` (`exam_id`),
  KEY `marks_subject_id_foreign` (`subject_id`),
  KEY `marks_class_id_foreign` (`class_id`),
  KEY `marks_entered_by_foreign` (`entered_by`),
  CONSTRAINT `marks_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marks_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `examinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marks_subject_id_foreign` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `marks_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `marks_entered_by_foreign` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `exam_class_status`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `exam_class_status`;
CREATE TABLE `exam_class_status` (
  `exam_id` bigint(20) UNSIGNED NOT NULL,
  `class_id` bigint(20) UNSIGNED NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'draft',
  `remarks` text DEFAULT NULL,
  `submitted_by` bigint(20) UNSIGNED DEFAULT NULL,
  `submitted_at` varchar(50) DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`exam_id`, `class_id`),
  KEY `exam_class_status_class_id_foreign` (`class_id`),
  KEY `exam_class_status_submitted_by_foreign` (`submitted_by`),
  KEY `exam_class_status_approved_by_foreign` (`approved_by`),
  CONSTRAINT `exam_class_status_exam_id_foreign` FOREIGN KEY (`exam_id`) REFERENCES `examinations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_class_status_class_id_foreign` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `exam_class_status_submitted_by_foreign` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exam_class_status_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `settings`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `key` varchar(191) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table structure for `audit_log`
-- --------------------------------------------------------
DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `username` varchar(191) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_log_created_at_index` (`created_at`),
  KEY `audit_log_action_index` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Laravel's own migrations tracking table (needed if you ever
-- also run `php artisan migrate` against this same database)
-- --------------------------------------------------------
DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` varchar(191) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2026_01_01_000001_create_classes_table', 1),
('2026_01_01_000002_create_users_table', 1),
('2026_01_01_000003_create_subjects_table', 1),
('2026_01_01_000004_create_class_subjects_table', 1),
('2026_01_01_000005_create_students_table', 1),
('2026_01_01_000006_create_examinations_table', 1),
('2026_01_01_000007_create_marks_table', 1),
('2026_01_01_000008_create_exam_class_status_table', 1),
('2026_01_01_000009_create_settings_table', 1),
('2026_01_01_000010_create_audit_log_table', 1);

-- --------------------------------------------------------
-- Seed data (mirrors database/seeders/DatabaseSeeder.php exactly)
-- --------------------------------------------------------

-- classes (17 rows)
INSERT INTO `classes` (`id`, `name`, `standard`, `stream`, `sort_order`, `teacher_id`, `last_promoted_year`, `created_at`, `updated_at`) VALUES
(1, 'Standard One A', 1, 'A', 1, NULL, '2026', NOW(), NOW()),
(2, 'Standard One B', 1, 'B', 2, NULL, '2026', NOW(), NOW()),
(3, 'Standard Two A', 2, 'A', 3, NULL, '2026', NOW(), NOW()),
(4, 'Standard Two B', 2, 'B', 4, NULL, '2026', NOW(), NOW()),
(5, 'Standard Three A', 3, 'A', 5, NULL, '2026', NOW(), NOW()),
(6, 'Standard Three B', 3, 'B', 6, NULL, '2026', NOW(), NOW()),
(7, 'Standard Four A', 4, 'A', 7, NULL, '2026', NOW(), NOW()),
(8, 'Standard Four B', 4, 'B', 8, NULL, '2026', NOW(), NOW()),
(9, 'Standard Five A', 5, 'A', 9, NULL, '2026', NOW(), NOW()),
(10, 'Standard Five B', 5, 'B', 10, NULL, '2026', NOW(), NOW()),
(11, 'Standard Five C', 5, 'C', 11, NULL, '2026', NOW(), NOW()),
(12, 'Standard Six A', 6, 'A', 12, NULL, '2026', NOW(), NOW()),
(13, 'Standard Six B', 6, 'B', 13, NULL, '2026', NOW(), NOW()),
(14, 'Standard Six C', 6, 'C', 14, NULL, '2026', NOW(), NOW()),
(15, 'Standard Seven A', 7, 'A', 15, NULL, '2026', NOW(), NOW()),
(16, 'Standard Seven B', 7, 'B', 16, NULL, '2026', NOW(), NOW()),
(17, 'Standard Seven C', 7, 'C', 17, NULL, '2026', NOW(), NOW());

-- subjects (9 rows)
INSERT INTO `subjects` (`id`, `name`, `created_at`, `updated_at`) VALUES
(1, 'SUMI', NOW(), NOW()),
(2, 'Kiswahili', NOW(), NOW()),
(3, 'English', NOW(), NOW()),
(4, 'Mazingira', NOW(), NOW()),
(5, 'Dini', NOW(), NOW()),
(6, 'Mathematics', NOW(), NOW()),
(7, 'Arabic', NOW(), NOW()),
(8, 'S.JAMII', NOW(), NOW()),
(9, 'Science and Technology', NOW(), NOW());

-- class_subjects mapping
INSERT INTO `class_subjects` (`class_id`, `subject_id`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(1, 6),
(2, 1),
(2, 2),
(2, 3),
(2, 4),
(2, 5),
(2, 6),
(3, 1),
(3, 2),
(3, 3),
(3, 4),
(3, 5),
(3, 6),
(4, 1),
(4, 2),
(4, 3),
(4, 4),
(4, 5),
(4, 6),
(5, 1),
(5, 2),
(5, 3),
(5, 4),
(5, 5),
(5, 6),
(6, 1),
(6, 2),
(6, 3),
(6, 4),
(6, 5),
(6, 6),
(7, 2),
(7, 3),
(7, 6),
(7, 5),
(7, 7),
(7, 8),
(7, 9),
(7, 1),
(8, 2),
(8, 3),
(8, 6),
(8, 5),
(8, 7),
(8, 8),
(8, 9),
(8, 1),
(9, 2),
(9, 3),
(9, 6),
(9, 5),
(9, 7),
(9, 8),
(9, 9),
(9, 1),
(10, 2),
(10, 3),
(10, 6),
(10, 5),
(10, 7),
(10, 8),
(10, 9),
(10, 1),
(11, 2),
(11, 3),
(11, 6),
(11, 5),
(11, 7),
(11, 8),
(11, 9),
(11, 1),
(12, 2),
(12, 3),
(12, 6),
(12, 5),
(12, 7),
(12, 8),
(12, 9),
(12, 1),
(13, 2),
(13, 3),
(13, 6),
(13, 5),
(13, 7),
(13, 8),
(13, 9),
(13, 1),
(14, 2),
(14, 3),
(14, 6),
(14, 5),
(14, 7),
(14, 8),
(14, 9),
(14, 1),
(15, 2),
(15, 3),
(15, 6),
(15, 5),
(15, 7),
(15, 8),
(15, 9),
(15, 1),
(16, 2),
(16, 3),
(16, 6),
(16, 5),
(16, 7),
(16, 8),
(16, 9),
(16, 1),
(17, 2),
(17, 3),
(17, 6),
(17, 5),
(17, 7),
(17, 8),
(17, 9),
(17, 1);

-- examinations
INSERT INTO `examinations` (`id`, `exam_type`, `academic_year`, `created_at`, `updated_at`) VALUES
(1, 'MID TERM', '2026', NOW(), NOW()),
(2, 'FIRST TERM', '2026', NOW(), NOW()),
(3, 'SECOND MID TERM', '2026', NOW(), NOW()),
(4, 'SECOND TERM', '2026', NOW(), NOW());

-- default headmaster user (username: headmaster / password: admin123 - MUST be changed on first login)
INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `class_id`, `active`, `must_change_password`, `photo_path`, `title`, `failed_login_attempts`, `locked_until`, `remember_token`, `created_at`, `updated_at`) VALUES
(1, 'headmaster', '$2y$10$.q7TIz/e5HRbeIP0H08MWOO8LjHXvspqkWPoMJOkJzjfYPB8k6DwO', 'Head Master', 'headmaster', NULL, 1, 1, NULL, NULL, 0, NULL, NULL, NOW(), NOW());

-- settings
INSERT INTO `settings` (`key`, `value`) VALUES
('school_name', 'KISAUNI PRIMARY SCHOOL'),
('academic_year', '2026'),
('logo_path', 'images/logo.png'),
('classes_streams_migrated', '1');

SET FOREIGN_KEY_CHECKS=1;
COMMIT;

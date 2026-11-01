-- 华政选课 · MySQL 表结构
-- {{PREFIX}} 会被安装程序替换为实际表前缀（默认 hz_）
-- 也可以手动把 {{PREFIX}} 替换成 hz_ 后直接导入

CREATE TABLE IF NOT EXISTS `{{PREFIX}}users` (
  `id` varchar(32) NOT NULL,
  `email` varchar(120) NOT NULL,
  `nickname` varchar(48) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `student_id` varchar(32) NOT NULL DEFAULT '',
  `grade` varchar(32) NOT NULL DEFAULT '',
  `bio` varchar(300) NOT NULL DEFAULT '',
  `avatar_color` varchar(16) NOT NULL DEFAULT '#2f7d51',
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `can_upload_courses` tinyint(1) NOT NULL DEFAULT 0,
  `verified` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` bigint NOT NULL DEFAULT 0,
  `banned` tinyint(1) NOT NULL DEFAULT 0,
  `ban_reason` varchar(200) NOT NULL DEFAULT '',
  `joined_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  UNIQUE KEY `uk_users_nickname` (`nickname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 站点级设置（例如闭站维护开关）
CREATE TABLE IF NOT EXISTS `{{PREFIX}}settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}courses` (
  `id` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `teacher` varchar(60) NOT NULL,
  `code` varchar(32) NOT NULL DEFAULT '',
  `credit` varchar(8) NOT NULL DEFAULT '',
  `category` varchar(20) NOT NULL DEFAULT '其他',
  `exam_mode` varchar(20) NOT NULL DEFAULT '',
  `language` varchar(20) NOT NULL DEFAULT '',
  `college` varchar(40) NOT NULL DEFAULT '',
  `intro` text,
  `created_by` varchar(32) NOT NULL DEFAULT '',
  `created_at` bigint NOT NULL,
  `updated_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_courses_name` (`name`),
  KEY `idx_courses_teacher` (`teacher`),
  KEY `idx_courses_category` (`category`),
  KEY `idx_courses_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 同一门课（同名同教师）在学校表格里可能有多个课程代码，单独存以便按任一代码检索
CREATE TABLE IF NOT EXISTS `{{PREFIX}}course_codes` (
  `course_id` varchar(32) NOT NULL,
  `code` varchar(32) NOT NULL,
  PRIMARY KEY (`course_id`, `code`),
  KEY `idx_codes_code` (`code`),
  CONSTRAINT `fk_cc_course` FOREIGN KEY (`course_id`)
      REFERENCES `{{PREFIX}}courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}reviews` (
  `id` varchar(32) NOT NULL,
  `course_id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `rating` tinyint NOT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `content` text NOT NULL,
  `tags` varchar(255) NOT NULL DEFAULT '[]',
  `take_again` tinyint(1) NOT NULL DEFAULT 0,
  `anonymous` tinyint(1) NOT NULL DEFAULT 0,
  `semester` varchar(32) NOT NULL DEFAULT '',
  `created_at` bigint NOT NULL,
  `updated_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_review_course_user` (`course_id`, `user_id`),
  KEY `idx_reviews_course` (`course_id`),
  KEY `idx_reviews_user` (`user_id`),
  CONSTRAINT `fk_reviews_course` FOREIGN KEY (`course_id`) REFERENCES `{{PREFIX}}courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}posts` (
  `id` varchar(32) NOT NULL,
  `type` varchar(10) NOT NULL DEFAULT 'chat',
  `course_id` varchar(32) DEFAULT NULL,
  `user_id` varchar(32) NOT NULL,
  `title` varchar(160) NOT NULL,
  `content` text NOT NULL,
  `tags` varchar(255) NOT NULL DEFAULT '[]',
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` bigint NOT NULL,
  `updated_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_posts_type` (`type`),
  KEY `idx_posts_course` (`course_id`),
  KEY `idx_posts_user` (`user_id`),
  CONSTRAINT `fk_posts_course` FOREIGN KEY (`course_id`) REFERENCES `{{PREFIX}}courses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}comments` (
  `id` varchar(32) NOT NULL,
  `post_id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `content` text NOT NULL,
  `created_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post` (`post_id`),
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `{{PREFIX}}posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}likes` (
  `id` varchar(32) NOT NULL,
  `target_type` varchar(10) NOT NULL,
  `target_id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `created_at` bigint NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_like_target_user` (`target_type`, `target_id`, `user_id`),
  KEY `idx_likes_target` (`target_type`, `target_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}sessions` (
  `token` varchar(64) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `created_at` bigint NOT NULL,
  `expires_at` bigint NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_sessions_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 学生认证申请。file_path 为空表示证明材料已按隐私策略删除
CREATE TABLE IF NOT EXISTS `{{PREFIX}}verifications` (
  `id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `file_name` varchar(200) NOT NULL DEFAULT '',
  `file_ext` varchar(16) NOT NULL DEFAULT '',
  `file_size` int NOT NULL DEFAULT 0,
  `file_path` varchar(255) NOT NULL DEFAULT '',
  `note` varchar(500) NOT NULL DEFAULT '',
  `admin_note` varchar(500) NOT NULL DEFAULT '',
  `reviewer_id` varchar(32) NOT NULL DEFAULT '',
  `created_at` bigint NOT NULL DEFAULT 0,
  `reviewed_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_ver_user` FOREIGN KEY (`user_id`) REFERENCES `{{PREFIX}}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}notifications` (
  `id` varchar(32) NOT NULL,
  `user_id` varchar(32) NOT NULL,
  `kind` varchar(32) NOT NULL DEFAULT 'system',
  `title` varchar(120) NOT NULL DEFAULT '',
  `content` varchar(2000) NOT NULL,
  `link` varchar(200) NOT NULL DEFAULT '',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `{{PREFIX}}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}conversations` (
  `id` varchar(32) NOT NULL,
  `user1_id` varchar(32) NOT NULL,
  `user2_id` varchar(32) NOT NULL,
  `last_message` varchar(300) NOT NULL DEFAULT '',
  `last_sender_id` varchar(32) NOT NULL DEFAULT '',
  `last_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pair` (`user1_id`, `user2_id`),
  KEY `idx_u1` (`user1_id`),
  KEY `idx_u2` (`user2_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{{PREFIX}}messages` (
  `id` varchar(32) NOT NULL,
  `conversation_id` varchar(32) NOT NULL,
  `sender_id` varchar(32) NOT NULL,
  `content` varchar(2000) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` bigint NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_conv` (`conversation_id`),
  KEY `idx_sender` (`sender_id`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `{{PREFIX}}conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_msg_user` FOREIGN KEY (`sender_id`) REFERENCES `{{PREFIX}}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

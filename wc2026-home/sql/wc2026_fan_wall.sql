-- =====================================================================
--  WC2026 Fan Wall — MySQL schema
--  Run once on the WC2026 database. Tables are prefixed WC2026_ to match
--  the rest of the challenge schema (WC2026_Users, WC2026_Predictions…).
-- =====================================================================

-- ---------------------------------------------------------------------
-- Posts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `WC2026_Fan_Wall` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT             NOT NULL,
    `author_name`    VARCHAR(150)    NOT NULL,
    `author_location` VARCHAR(150)   DEFAULT NULL,
    `body`           TEXT            NOT NULL,
    `photo_path`     VARCHAR(255)    DEFAULT NULL,
    `likes_count`    INT             NOT NULL DEFAULT 0,
    `comments_count` INT             NOT NULL DEFAULT 0,
    `status`         ENUM('Active','Hidden','Deleted') NOT NULL DEFAULT 'Active',
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created`        (`created_at`),
    KEY `idx_user`           (`user_id`),
    KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Comments
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `WC2026_Fan_Wall_Comments` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id`     BIGINT UNSIGNED NOT NULL,
    `user_id`     INT             NOT NULL,
    `author_name` VARCHAR(150)    NOT NULL,
    `body`        TEXT            NOT NULL,
    `status`      ENUM('Active','Hidden','Deleted') NOT NULL DEFAULT 'Active',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_post` (`post_id`, `created_at`),
    CONSTRAINT `fk_fanwall_comment_post`
        FOREIGN KEY (`post_id`) REFERENCES `WC2026_Fan_Wall` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Likes (one row per user per post)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `WC2026_Fan_Wall_Likes` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `post_id`    BIGINT UNSIGNED NOT NULL,
    `user_id`    INT             NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_post_user` (`post_id`, `user_id`),
    KEY `idx_post` (`post_id`),
    CONSTRAINT `fk_fanwall_like_post`
        FOREIGN KEY (`post_id`) REFERENCES `WC2026_Fan_Wall` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  Notification-bar query reference (what /api/social_feed.php?mode=notify runs):
--    SELECT * FROM WC2026_Fan_Wall
--    WHERE status='Active' AND created_at >= NOW() - INTERVAL 15 MINUTE
--    ORDER BY created_at DESC
--    LIMIT 30;
--  -> "posts from the last 15 minutes, capped at the latest 30".
-- =====================================================================

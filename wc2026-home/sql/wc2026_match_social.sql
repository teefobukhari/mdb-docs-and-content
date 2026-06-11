-- =====================================================================
--  WC2026 per-match comments & reactions
--  Lets fans comment and react on each match (used by the predict screen).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `WC2026_Match_Comments` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_id`    BIGINT          NOT NULL,
    `user_id`     INT             NOT NULL,
    `author_name` VARCHAR(150)    NOT NULL,
    `body`        TEXT            NOT NULL,
    `status`      ENUM('Active','Hidden','Deleted') NOT NULL DEFAULT 'Active',
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_match` (`match_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One reaction per user per match (re-reacting replaces the previous one).
CREATE TABLE IF NOT EXISTS `WC2026_Match_Reactions` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `match_id`   BIGINT          NOT NULL,
    `user_id`    INT             NOT NULL,
    `reaction`   ENUM('like','fire','goal','heart') NOT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_match_user` (`match_id`, `user_id`),
    KEY `idx_match` (`match_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

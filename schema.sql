-- ============================================================
-- SplitPay — Database Schema
-- MySQL 8 · InnoDB · utf8mb4
-- Run: mysql -u root -p splitpay < schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS splitpay
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE splitpay;

-- ── Table 1: users ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
  user_id       INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)    NOT NULL,
  email         VARCHAR(100)   NOT NULL,
  password_hash VARCHAR(255)   NOT NULL,
  display_name  VARCHAR(100)   NOT NULL,
  created_at    DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME       NULL     ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_email    (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 2: groups ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `groups` (
  group_id    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  group_name  VARCHAR(100)  NOT NULL,
  description TEXT          NULL,
  created_by  INT UNSIGNED  NOT NULL,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id),
  CONSTRAINT fk_groups_created_by FOREIGN KEY (created_by) REFERENCES users (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 3: group_members ──────────────────────────────────
CREATE TABLE IF NOT EXISTS group_members (
  member_id  INT UNSIGNED                NOT NULL AUTO_INCREMENT,
  group_id   INT UNSIGNED                NOT NULL,
  user_id    INT UNSIGNED                NOT NULL,
  role       ENUM('admin','member')      NOT NULL DEFAULT 'member',
  joined_at  DATETIME                    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id),
  UNIQUE KEY uq_group_user (group_id, user_id),
  CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES `groups` (group_id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_user  FOREIGN KEY (user_id)  REFERENCES users     (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 4: projects ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS projects (
  project_id   INT UNSIGNED               NOT NULL AUTO_INCREMENT,
  group_id     INT UNSIGNED               NOT NULL,
  project_name VARCHAR(150)               NOT NULL,
  description  TEXT                       NULL,
  event_date   DATE                       NULL,
  status       ENUM('open','settled')     NOT NULL DEFAULT 'open',
  created_by   INT UNSIGNED               NOT NULL,
  created_at   DATETIME                   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id),
  CONSTRAINT fk_proj_group      FOREIGN KEY (group_id)   REFERENCES `groups` (group_id),
  CONSTRAINT fk_proj_created_by FOREIGN KEY (created_by) REFERENCES users     (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 5: expenses ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS expenses (
  expense_id  INT UNSIGNED                         NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED                         NOT NULL,
  description VARCHAR(255)                         NOT NULL,
  amount      DECIMAL(10,2)                        NOT NULL,
  paid_by     INT UNSIGNED                         NOT NULL,
  status      ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  created_by  INT UNSIGNED                         NOT NULL,
  created_at  DATETIME                             NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (expense_id),
  CONSTRAINT chk_amount CHECK (amount > 0),
  CONSTRAINT fk_exp_project    FOREIGN KEY (project_id) REFERENCES projects (project_id),
  CONSTRAINT fk_exp_paid_by    FOREIGN KEY (paid_by)    REFERENCES users    (user_id),
  CONSTRAINT fk_exp_created_by FOREIGN KEY (created_by) REFERENCES users    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 6: expense_participants ───────────────────────────
CREATE TABLE IF NOT EXISTS expense_participants (
  ep_id        INT UNSIGNED                         NOT NULL AUTO_INCREMENT,
  expense_id   INT UNSIGNED                         NOT NULL,
  user_id      INT UNSIGNED                         NOT NULL,
  status       ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
  responded_at DATETIME                             NULL,
  PRIMARY KEY (ep_id),
  UNIQUE KEY uq_ep (expense_id, user_id),
  CONSTRAINT fk_ep_expense FOREIGN KEY (expense_id) REFERENCES expenses (expense_id) ON DELETE CASCADE,
  CONSTRAINT fk_ep_user    FOREIGN KEY (user_id)    REFERENCES users    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 7: settlements ────────────────────────────────────
CREATE TABLE IF NOT EXISTS settlements (
  settlement_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  project_id    INT UNSIGNED  NOT NULL,
  payer_id      INT UNSIGNED  NOT NULL,
  receiver_id   INT UNSIGNED  NOT NULL,
  amount        DECIMAL(10,2) NOT NULL,
  settled_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (settlement_id),
  CONSTRAINT fk_set_project  FOREIGN KEY (project_id) REFERENCES projects (project_id),
  CONSTRAINT fk_set_payer    FOREIGN KEY (payer_id)   REFERENCES users    (user_id),
  CONSTRAINT fk_set_receiver FOREIGN KEY (receiver_id) REFERENCES users   (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 8: settlement_payments (partial/approval flow) ──────────────────────────────────
CREATE TABLE IF NOT EXISTS settlement_payments (
  payment_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  to_user_id   INT UNSIGNED NOT NULL,
  amount       DECIMAL(10,2) NOT NULL,
  status       ENUM('pending', 'confirmed', 'rejected') NOT NULL DEFAULT 'pending',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_by INT UNSIGNED NULL,
  confirmed_at DATETIME NULL,
  note         TEXT NULL,
  PRIMARY KEY (payment_id),
  KEY idx_sp_project (project_id),
  KEY idx_sp_from    (from_user_id),
  KEY idx_sp_to      (to_user_id),
  CONSTRAINT fk_sp_project FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_from    FOREIGN KEY (from_user_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_sp_to      FOREIGN KEY (to_user_id)   REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Table 9: notifications ──────────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
  notification_id INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED  NOT NULL,
  type            VARCHAR(50)   NOT NULL,
  reference_id    INT UNSIGNED  NULL,
  message         VARCHAR(255)  NOT NULL,
  is_read         TINYINT(1)    NOT NULL DEFAULT 0,
  created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notification_id),
  KEY idx_notif_user (user_id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

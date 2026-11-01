-- MedRadar schema (MySQL 8 / MariaDB 10.4+)
-- Import:  mysql -u root -p < database/schema.sql   (or phpMyAdmin → Import)
-- Creates a NEW database called `medradar`; your old `medicine_finder` DB is not touched.

CREATE DATABASE IF NOT EXISTS medradar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE medradar;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS rate_limits, admin_events, information_reports, stock_events,
                     availability_requests, inventory, medicines, pharmacies, users;
SET FOREIGN_KEY_CHECKS = 1;

-- Accounts: pharmacy owners and administrators (no patient accounts)
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('pharmacy','admin') NOT NULL DEFAULT 'pharmacy',
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- Public pharmacy profile + approval workflow
-- pending -> approved | rejected ; approved -> suspended ; suspended -> approved (reactivate)
CREATE TABLE pharmacies (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_user_id   INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  contact_person  VARCHAR(100) NOT NULL,
  phone           VARCHAR(20)  NOT NULL,
  address         VARCHAR(255) NOT NULL,
  locality        VARCHAR(80)  NOT NULL,
  pin_code        CHAR(6)      NOT NULL,
  hours           TEXT NULL,                    -- JSON {"mon":"09:00-21:00","sun":""}
  closed_until    DATE NULL,                    -- temporary closure (inclusive)
  closure_note    VARCHAR(160) NULL,
  approval_status ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  review_reason   VARCHAR(255) NULL,
  reviewed_by     INT UNSIGNED NULL,
  reviewed_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pharm_owner (owner_user_id),
  KEY idx_pharm_status (approval_status),
  KEY idx_pharm_locality (locality),
  KEY idx_pharm_pin (pin_code),
  CONSTRAINT fk_pharm_owner    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pharm_reviewer FOREIGN KEY (reviewed_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Central catalogue. Same brand + different strength/form = different rows.
-- Archived rows are hidden from search but stay referenced by inventory/history.
CREATE TABLE medicines (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_name    VARCHAR(120) NOT NULL,
  ingredient_name VARCHAR(120) NOT NULL,
  strength        VARCHAR(40)  NOT NULL,
  dosage_form     VARCHAR(40)  NOT NULL,
  manufacturer    VARCHAR(100) NULL,
  archived        TINYINT(1) NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_med_product (product_name, strength, dosage_form),   -- blocks obvious duplicates (case-insensitive collation)
  KEY idx_med_ingredient (ingredient_name),
  KEY idx_med_archived (archived)
) ENGINE=InnoDB;

-- One listing per pharmacy/product pair.
-- created_at = row created, updated_at = any change, last_confirmed_at = explicit stock confirmation only.
CREATE TABLE inventory (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id       INT UNSIGNED NOT NULL,
  medicine_id       INT UNSIGNED NOT NULL,
  status            ENUM('in_stock','low_stock','out_of_stock') NOT NULL DEFAULT 'in_stock',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_confirmed_at DATETIME NULL,
  UNIQUE KEY uq_inventory_pair (pharmacy_id, medicine_id),
  KEY idx_inventory_search (medicine_id, is_active, status, last_confirmed_at),
  CONSTRAINT fk_inv_pharmacy FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_medicine FOREIGN KEY (medicine_id) REFERENCES medicines(id)  ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Private availability-confirmation requests. Only a SHA-256 hash of the tracking token is stored.
CREATE TABLE availability_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  inventory_id  INT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,
  ip_hash       CHAR(64) NOT NULL,
  status        ENUM('pending','confirmed_available','confirmed_unavailable','cannot_confirm','expired') NOT NULL DEFAULT 'pending',
  response_note VARCHAR(200) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  responded_at  DATETIME NULL,
  UNIQUE KEY uq_req_token (token_hash),
  KEY idx_req_inventory (inventory_id, status),
  KEY idx_req_expiry (status, expires_at),
  CONSTRAINT fk_req_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Inventory + request audit trail (never stores passwords or tokens)
CREATE TABLE stock_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_id     INT UNSIGNED NULL,
  inventory_id INT UNSIGNED NULL,
  action       VARCHAR(40) NOT NULL,
  before_value VARCHAR(120) NULL,
  after_value  VARCHAR(120) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_se_inventory (inventory_id, created_at),
  CONSTRAINT fk_se_actor     FOREIGN KEY (actor_id)     REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT fk_se_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE information_reports (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pharmacy_id  INT UNSIGNED NOT NULL,
  inventory_id INT UNSIGNED NULL,
  category     ENUM('stock','contact','address','closure','misleading') NOT NULL,
  explanation  VARCHAR(300) NULL,
  status       ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  admin_note   VARCHAR(255) NULL,
  reviewed_by  INT UNSIGNED NULL,
  reviewed_at  DATETIME NULL,
  ip_hash      CHAR(64) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rep_status (status, created_at),
  CONSTRAINT fk_rep_pharmacy  FOREIGN KEY (pharmacy_id)  REFERENCES pharmacies(id) ON DELETE CASCADE,
  CONSTRAINT fk_rep_inventory FOREIGN KEY (inventory_id) REFERENCES inventory(id)  ON DELETE SET NULL,
  CONSTRAINT fk_rep_reviewer  FOREIGN KEY (reviewed_by)  REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE admin_events (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_id      INT UNSIGNED NULL,
  affected_type VARCHAR(30) NOT NULL,
  affected_id   INT UNSIGNED NULL,
  action        VARCHAR(40) NOT NULL,
  details       VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ae_affected (affected_type, affected_id),
  KEY idx_ae_created (created_at),
  CONSTRAINT fk_ae_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Simple sliding-window rate limiting (login, requests, reports). Keys are hashed.
CREATE TABLE rate_limits (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket     VARCHAR(30) NOT NULL,
  key_hash   CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rl (bucket, key_hash, created_at)
) ENGINE=InnoDB;

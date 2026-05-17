-- MySQL 8+ schema for shared hosting/phpMyAdmin users
-- NOTE: This file does not create/select database.
-- Select your existing database in phpMyAdmin, then import this file.

CREATE TABLE IF NOT EXISTS users (
  id CHAR(24) NOT NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  message_balance INT NOT NULL DEFAULT 0,
  auth_token TEXT NULL,
  api_token TEXT NULL,
  api_token_created_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL DEFAULT 'Admin',
  email VARCHAR(255) NOT NULL,
  password VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS token_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  token TEXT NOT NULL,
  owner_type VARCHAR(20) NOT NULL,
  owner_id VARCHAR(64) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_token_hash (token_hash),
  KEY idx_owner (owner_type, owner_id),
  KEY idx_active_expiry (is_active, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS whatsapp_clients (
  id CHAR(24) NOT NULL,
  user_id CHAR(24) NOT NULL,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  client_id VARCHAR(190) NOT NULL,
  status ENUM('disconnected', 'initializing', 'qr_ready', 'connected', 'auth_failure')
    NOT NULL DEFAULT 'disconnected',
  qr_code LONGTEXT NULL,
  session_path VARCHAR(500) NULL,
  last_connected DATETIME NULL,
  messages_sent INT NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_whatsapp_clients_client_id (client_id),
  KEY idx_whatsapp_clients_user_id (user_id),
  CONSTRAINT fk_whatsapp_clients_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS campaigns (
  id CHAR(24) NOT NULL,
  user_id CHAR(24) NOT NULL,
  client_id CHAR(24) NOT NULL,
  name VARCHAR(200) NOT NULL,
  message TEXT NOT NULL,
  media_url VARCHAR(2048) NULL,
  media_type ENUM('image', 'video', 'document') NULL,
  status ENUM('draft', 'running', 'paused', 'completed', 'failed')
    NOT NULL DEFAULT 'draft',
  min_delay INT NOT NULL DEFAULT 20000,
  max_delay INT NOT NULL DEFAULT 30000,
  total_contacts INT NOT NULL DEFAULT 0,
  sent_count INT NOT NULL DEFAULT 0,
  failed_count INT NOT NULL DEFAULT 0,
  pending_count INT NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_campaigns_user_id (user_id),
  KEY idx_campaigns_client_id (client_id),
  CONSTRAINT fk_campaigns_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_campaigns_client
    FOREIGN KEY (client_id) REFERENCES whatsapp_clients (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
  id CHAR(24) NOT NULL,
  user_id CHAR(24) NOT NULL,
  campaign_id CHAR(24) NOT NULL,
  name VARCHAR(200) NULL,
  phone VARCHAR(40) NOT NULL,
  variables JSON NULL,
  status ENUM('pending', 'sent', 'failed', 'skipped') NOT NULL DEFAULT 'pending',
  sent_at DATETIME NULL,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_contacts_campaign_status (campaign_id, status),
  KEY idx_contacts_campaign_phone (campaign_id, phone),
  KEY idx_contacts_user_id (user_id),
  CONSTRAINT fk_contacts_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_contacts_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns (id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_logs (
  id CHAR(24) NOT NULL,
  user_id CHAR(24) NOT NULL,
  client_id CHAR(24) NOT NULL,
  campaign_id CHAR(24) NULL,
  contact_id CHAR(24) NULL,
  phone VARCHAR(40) NOT NULL,
  message TEXT NOT NULL,
  direction ENUM('outgoing', 'incoming') NOT NULL DEFAULT 'outgoing',
  status ENUM('sent', 'failed', 'received') NOT NULL DEFAULT 'sent',
  whatsapp_message_id VARCHAR(255) NULL,
  error TEXT NULL,
  timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_message_logs_user_timestamp (user_id, timestamp),
  KEY idx_message_logs_campaign_timestamp (campaign_id, timestamp),
  KEY idx_message_logs_client_timestamp (client_id, timestamp),
  CONSTRAINT fk_message_logs_user
    FOREIGN KEY (user_id) REFERENCES users (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_message_logs_client
    FOREIGN KEY (client_id) REFERENCES whatsapp_clients (id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_message_logs_campaign
    FOREIGN KEY (campaign_id) REFERENCES campaigns (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_message_logs_contact
    FOREIGN KEY (contact_id) REFERENCES contacts (id)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

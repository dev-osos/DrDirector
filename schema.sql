CREATE DATABASE IF NOT EXISTS dr_director CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dr_director;

CREATE TABLE IF NOT EXISTS drug_checks (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  drug_key      VARCHAR(190) NOT NULL,
  drug_name     VARCHAR(190) NOT NULL,
  verdict       ENUM('safe','unsafe','consult','unknown') NOT NULL,
  safe_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  unsafe_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  neutral_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  sources_json  MEDIUMTEXT NOT NULL,
  asked_count   INT UNSIGNED NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_drug_key (drug_key),
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

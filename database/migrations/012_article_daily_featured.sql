ALTER TABLE articles
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER is_indexable,
  ADD COLUMN featured_date DATE NULL AFTER is_featured,
  ADD COLUMN imported_at DATETIME NULL AFTER featured_date,
  ADD COLUMN source_excerpt TEXT NULL AFTER imported_at,
  ADD KEY idx_articles_featured (is_featured, featured_date, published_at),
  ADD KEY idx_articles_imported (imported_at);

CREATE TABLE IF NOT EXISTS article_import_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(36) NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running', 'completed', 'failed') NOT NULL DEFAULT 'running',
  sources_checked INT UNSIGNED NOT NULL DEFAULT 0,
  items_found INT UNSIGNED NOT NULL DEFAULT 0,
  items_imported INT UNSIGNED NOT NULL DEFAULT 0,
  items_skipped INT UNSIGNED NOT NULL DEFAULT 0,
  items_featured INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_article_import_runs_public_id (public_id),
  KEY idx_article_import_runs_started (started_at),
  KEY idx_article_import_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

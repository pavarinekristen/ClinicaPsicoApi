ALTER TABLE reservas
  ADD COLUMN confirm_code CHAR(6) NULL AFTER lock_token,
  ADD COLUMN confirm_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER confirm_code;

ALTER TABLE reservas
  ADD COLUMN created_ip VARCHAR(45) NULL AFTER confirm_attempts,
  ADD KEY idx_reservas_ip_status (created_ip, status, locked_until);

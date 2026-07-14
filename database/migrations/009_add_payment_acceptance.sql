ALTER TABLE reservas
  ADD COLUMN payment_status ENUM('aguardando_pix', 'pix_recebido', 'nao_aplicavel') NOT NULL DEFAULT 'aguardando_pix' AFTER status,
  ADD COLUMN pix_received_at DATETIME NULL AFTER payment_status,
  ADD COLUMN aceite_termos TINYINT(1) NOT NULL DEFAULT 0 AFTER created_ip,
  ADD COLUMN aceite_privacidade TINYINT(1) NOT NULL DEFAULT 0 AFTER aceite_termos,
  ADD COLUMN versao_termos VARCHAR(40) NULL AFTER aceite_privacidade,
  ADD COLUMN versao_privacidade VARCHAR(40) NULL AFTER versao_termos,
  ADD COLUMN data_hora_aceite DATETIME NULL AFTER versao_privacidade,
  ADD COLUMN origem_aceite VARCHAR(80) NULL AFTER data_hora_aceite,
  ADD COLUMN texto_aceite VARCHAR(800) NULL AFTER origem_aceite,
  ADD COLUMN aceite_user_agent VARCHAR(255) NULL AFTER texto_aceite,
  ADD COLUMN aceite_ip VARCHAR(45) NULL AFTER aceite_user_agent,
  ADD KEY idx_reservas_payment_status (payment_status, pix_received_at);
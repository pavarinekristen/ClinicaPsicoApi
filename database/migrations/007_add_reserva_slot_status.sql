ALTER TABLE reserva_slots
  ADD COLUMN status ENUM('ativa', 'cancelada') NOT NULL DEFAULT 'ativa' AFTER ordem,
  ADD COLUMN cancelled_at DATETIME NULL AFTER status,
  ADD KEY idx_reserva_slots_status (status, slot_id);

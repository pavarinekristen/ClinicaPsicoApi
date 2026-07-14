ALTER TABLE agenda_slots
  ADD KEY idx_agenda_slots_inicio (slot_inicio);

ALTER TABLE reservas
  ADD KEY idx_reservas_status_updated (status, updated_at),
  ADD KEY idx_reservas_status_created (status, created_at);
CREATE TABLE IF NOT EXISTS reserva_slots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reserva_id BIGINT UNSIGNED NOT NULL,
  slot_id BIGINT UNSIGNED NOT NULL,
  ordem TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reserva_slots_reserva_slot (reserva_id, slot_id),
  KEY idx_reserva_slots_reserva (reserva_id, ordem),
  KEY idx_reserva_slots_slot (slot_id),
  CONSTRAINT fk_reserva_slots_reserva FOREIGN KEY (reserva_id) REFERENCES reservas(id) ON DELETE CASCADE,
  CONSTRAINT fk_reserva_slots_slot FOREIGN KEY (slot_id) REFERENCES agenda_slots(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO reserva_slots (reserva_id, slot_id, ordem)
SELECT id, slot_id, 1
FROM reservas;

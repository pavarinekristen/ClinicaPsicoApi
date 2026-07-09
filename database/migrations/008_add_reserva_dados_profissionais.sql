ALTER TABLE reservas
  ADD COLUMN cliente_crp VARCHAR(32) NULL AFTER cliente_whatsapp,
  ADD COLUMN publicos_atendidos VARCHAR(120) NULL AFTER cliente_crp,
  ADD COLUMN abordagem_trabalho VARCHAR(120) NULL AFTER publicos_atendidos;

ALTER TABLE agenda_slots
  ADD COLUMN cliente_crp VARCHAR(32) NULL AFTER cliente_whatsapp,
  ADD COLUMN publicos_atendidos VARCHAR(120) NULL AFTER cliente_crp,
  ADD COLUMN abordagem_trabalho VARCHAR(120) NULL AFTER publicos_atendidos;

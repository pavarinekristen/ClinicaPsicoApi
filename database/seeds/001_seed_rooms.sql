INSERT INTO salas (public_id, numero, nome, categoria)
VALUES
  ('sala1', 'Sala 01', 'Acolhimento', 'Individual'),
  ('sala2', 'Sala 02', 'Dialogo', 'Casal e grupos')
ON DUPLICATE KEY UPDATE
  numero = VALUES(numero),
  nome = VALUES(nome),
  categoria = VALUES(categoria),
  ativa = 1;

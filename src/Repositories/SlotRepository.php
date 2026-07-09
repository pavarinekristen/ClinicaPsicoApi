<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SlotRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function cleanupExpiredLocks(): void
    {
        $this->pdo->exec(
            "UPDATE reservas
             SET status = 'expirada',
                 updated_at = UTC_TIMESTAMP()
             WHERE status = 'lock_temporario'
               AND locked_until <= UTC_TIMESTAMP()"
        );

        $this->pdo->exec(
            "UPDATE agenda_slots
             SET status = 'livre',
                 lock_token = NULL,
                 locked_until = NULL,
                 cliente_nome = NULL,
                 cliente_whatsapp = NULL,
                 plano = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE status = 'lock_temporario'
               AND locked_until <= UTC_TIMESTAMP()"
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function slotsForDay(string $salaPublicId, string $date): array
    {
        $this->cleanupExpiredLocks();

        $stmt = $this->pdo->prepare(
            "SELECT s.public_id AS id,
                    s.slot_inicio,
                    s.slot_fim,
                    s.status,
                    s.locked_until,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), s.locked_until) AS seconds_to_unlock
             FROM agenda_slots s
             INNER JOIN salas r ON r.id = s.sala_id
             WHERE r.public_id = :sala_public_id
               AND DATE(CONVERT_TZ(s.slot_inicio, '+00:00', '-03:00')) = :date
             ORDER BY s.slot_inicio"
        );

        $stmt->execute(['sala_public_id' => $salaPublicId, 'date' => $date]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findSlotByPublicId(string $slotPublicId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.*, r.public_id AS sala_public_id, r.nome AS sala_nome, r.numero AS sala_numero
             FROM agenda_slots s
             INNER JOIN salas r ON r.id = s.sala_id
             WHERE s.public_id = :slot_public_id
             LIMIT 1"
        );

        $stmt->execute(['slot_public_id' => $slotPublicId]);
        $slot = $stmt->fetch();

        return $slot ?: null;
    }

    public function activeLocksForIp(string $ip): int
    {
        $this->cleanupExpiredLocks();

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total
             FROM reservas
             WHERE created_ip = :ip
               AND status = 'lock_temporario'
               AND locked_until > UTC_TIMESTAMP()"
        );
        $stmt->execute(['ip' => $ip]);

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** @return array<string, mixed> */
    public function lockSlot(string $slotPublicId, string $token, string $confirmCode, int $ttlMinutes, ?string $name, ?string $whatsapp, string $plan, string $createdIp): array
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'lock_temporario',
                     lock_token = :token,
                     locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl MINUTE),
                     cliente_nome = :cliente_nome,
                     cliente_whatsapp = :cliente_whatsapp,
                     plano = :plano,
                     updated_at = UTC_TIMESTAMP()
                 WHERE public_id = :slot_public_id
                   AND (
                     status = 'livre'
                     OR (status = 'lock_temporario' AND locked_until <= UTC_TIMESTAMP())
                   )
                 LIMIT 1"
            );

            $stmt->bindValue(':token', $token);
            $stmt->bindValue(':ttl', $ttlMinutes, PDO::PARAM_INT);
            $stmt->bindValue(':cliente_nome', $name);
            $stmt->bindValue(':cliente_whatsapp', $whatsapp);
            $stmt->bindValue(':plano', $plan);
            $stmt->bindValue(':slot_public_id', $slotPublicId);
            $stmt->execute();

            if ($stmt->rowCount() !== 1) {
                $this->pdo->rollBack();
                return [];
            }

            $slot = $this->findSlotByPublicId($slotPublicId);

            if (!$slot) {
                $this->pdo->rollBack();
                return [];
            }

            $reservationStmt = $this->pdo->prepare(
                "INSERT INTO reservas (
                    public_id,
                    slot_id,
                    sala_id,
                    cliente_nome,
                    cliente_whatsapp,
                    plano,
                    status,
                    lock_token,
                    confirm_code,
                    created_ip,
                    locked_until
                 )
                 VALUES (
                    UUID(),
                    :slot_id,
                    :sala_id,
                    :cliente_nome,
                    :cliente_whatsapp,
                    :plano,
                    'lock_temporario',
                    :lock_token,
                    :confirm_code,
                    :created_ip,
                    :locked_until
                 )"
            );

            $reservationStmt->execute([
                'slot_id' => $slot['id'],
                'sala_id' => $slot['sala_id'],
                'cliente_nome' => $name,
                'cliente_whatsapp' => $whatsapp,
                'plano' => $plan,
                'lock_token' => $token,
                'confirm_code' => $confirmCode,
                'created_ip' => $createdIp !== '' ? $createdIp : null,
                'locked_until' => $slot['locked_until'],
            ]);

            $reservation = $this->findReservationByToken($token);
            $this->pdo->commit();

            return [
                ...$slot,
                'reserva_public_id' => $reservation['public_id'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function confirmSlot(string $slotPublicId, string $token): bool
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE public_id = :slot_public_id
                   AND lock_token = :token
                   AND status = 'lock_temporario'
                   AND locked_until > UTC_TIMESTAMP()
                 LIMIT 1"
            );

            $stmt->execute(['slot_public_id' => $slotPublicId, 'token' => $token]);

            if ($stmt->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $reservationStmt = $this->pdo->prepare(
                "UPDATE reservas r
                 INNER JOIN agenda_slots s ON s.id = r.slot_id
                 SET r.status = 'confirmada',
                     r.locked_until = NULL,
                     r.confirmed_at = UTC_TIMESTAMP(),
                     r.updated_at = UTC_TIMESTAMP()
                 WHERE s.public_id = :slot_public_id
                   AND r.lock_token = :token
                   AND r.status = 'lock_temporario'"
            );
            $reservationStmt->execute(['slot_public_id' => $slotPublicId, 'token' => $token]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Confirma a reserva usando o codigo enviado pela equipe apos o PIX.
     * Retorna: ok | already_confirmed | not_found | expired | blocked | invalid
     */
    public function confirmReservationByCode(string $reservaPublicId, string $code): string
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.id,
                        r.slot_id,
                        r.status,
                        r.confirm_code,
                        r.confirm_attempts,
                        (r.locked_until > UTC_TIMESTAMP()) AS lock_valido
                 FROM reservas r
                 WHERE r.public_id = :public_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['public_id' => $reservaPublicId]);
            $reservation = $stmt->fetch();

            if (!$reservation) {
                $this->pdo->rollBack();
                return 'not_found';
            }

            if ($reservation['status'] === 'confirmada') {
                $this->pdo->rollBack();
                return 'already_confirmed';
            }

            if ($reservation['status'] !== 'lock_temporario' || !(int) $reservation['lock_valido']) {
                $this->pdo->rollBack();
                return 'expired';
            }

            if ((int) $reservation['confirm_attempts'] >= 5) {
                $this->pdo->rollBack();
                return 'blocked';
            }

            if ($reservation['confirm_code'] === null || !hash_equals((string) $reservation['confirm_code'], $code)) {
                $attemptStmt = $this->pdo->prepare(
                    'UPDATE reservas SET confirm_attempts = confirm_attempts + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id'
                );
                $attemptStmt->execute(['id' => $reservation['id']]);
                $this->pdo->commit();

                return 'invalid';
            }

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :slot_id
                   AND status = 'lock_temporario'"
            );
            $slotStmt->execute(['slot_id' => $reservation['slot_id']]);

            $reservationStmt = $this->pdo->prepare(
                "UPDATE reservas
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $reservationStmt->execute(['id' => $reservation['id']]);

            $this->pdo->commit();

            return 'ok';
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Confirmacao manual pela equipe (plano B quando o cliente nao consegue digitar o codigo).
     */
    public function adminConfirmReservation(string $reservaPublicId): bool
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, slot_id, status, (locked_until > UTC_TIMESTAMP()) AS lock_valido
                 FROM reservas
                 WHERE public_id = :public_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute(['public_id' => $reservaPublicId]);
            $reservation = $stmt->fetch();

            if (!$reservation) {
                $this->pdo->rollBack();
                return false;
            }

            if ($reservation['status'] === 'confirmada') {
                $this->pdo->rollBack();
                return true;
            }

            if ($reservation['status'] !== 'lock_temporario' || !(int) $reservation['lock_valido']) {
                $this->pdo->rollBack();
                return false;
            }

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :slot_id
                   AND status = 'lock_temporario'"
            );
            $slotStmt->execute(['slot_id' => $reservation['slot_id']]);

            $reservationStmt = $this->pdo->prepare(
                "UPDATE reservas
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $reservationStmt->execute(['id' => $reservation['id']]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Cancela/recusa um cadastro: reserva vira 'cancelada' e o horario volta a 'livre'.
     */
    public function adminCancelReservation(string $reservaPublicId): bool
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, slot_id, status FROM reservas WHERE public_id = :public_id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['public_id' => $reservaPublicId]);
            $reservation = $stmt->fetch();

            if (!$reservation) {
                $this->pdo->rollBack();
                return false;
            }

            if ($reservation['status'] === 'cancelada') {
                $this->pdo->rollBack();
                return true;
            }

            if (!in_array($reservation['status'], ['lock_temporario', 'confirmada'], true)) {
                $this->pdo->rollBack();
                return false;
            }

            $reservationStmt = $this->pdo->prepare(
                "UPDATE reservas
                 SET status = 'cancelada',
                     cancelled_at = UTC_TIMESTAMP(),
                     locked_until = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $reservationStmt->execute(['id' => $reservation['id']]);

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'livre',
                     lock_token = NULL,
                     locked_until = NULL,
                     confirmed_at = NULL,
                     cliente_nome = NULL,
                     cliente_whatsapp = NULL,
                     plano = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :slot_id
                   AND status IN ('lock_temporario', 'confirmada')"
            );
            $slotStmt->execute(['slot_id' => $reservation['slot_id']]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Edita os dados do cliente em um cadastro ativo (pendente ou confirmado).
     * Campos null permanecem como estao.
     */
    public function adminUpdateReservation(string $reservaPublicId, ?string $name, ?string $whatsapp, ?string $plan): bool
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, slot_id, status, cliente_nome, cliente_whatsapp, plano
                 FROM reservas WHERE public_id = :public_id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['public_id' => $reservaPublicId]);
            $reservation = $stmt->fetch();

            if (!$reservation || !in_array($reservation['status'], ['lock_temporario', 'confirmada'], true)) {
                $this->pdo->rollBack();
                return false;
            }

            $values = [
                'cliente_nome' => $name ?? $reservation['cliente_nome'],
                'cliente_whatsapp' => $whatsapp ?? $reservation['cliente_whatsapp'],
                'plano' => $plan ?? $reservation['plano'],
            ];

            $reservationStmt = $this->pdo->prepare(
                'UPDATE reservas
                 SET cliente_nome = :cliente_nome,
                     cliente_whatsapp = :cliente_whatsapp,
                     plano = :plano,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $reservationStmt->execute([...$values, 'id' => $reservation['id']]);

            $slotStmt = $this->pdo->prepare(
                'UPDATE agenda_slots
                 SET cliente_nome = :cliente_nome,
                     cliente_whatsapp = :cliente_whatsapp,
                     plano = :plano,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :slot_id'
            );
            $slotStmt->execute([...$values, 'slot_id' => $reservation['slot_id']]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function reservationsForDay(string $date): array
    {
        $this->cleanupExpiredLocks();

        $stmt = $this->pdo->prepare(
            "SELECT r.public_id AS reserva_id,
                    r.cliente_nome,
                    r.cliente_whatsapp,
                    r.plano,
                    r.status,
                    r.created_at,
                    s.public_id AS slot_id,
                    s.slot_inicio,
                    s.slot_fim,
                    sa.numero AS sala_numero,
                    sa.nome AS sala_nome
             FROM reservas r
             INNER JOIN agenda_slots s ON s.id = r.slot_id
             INNER JOIN salas sa ON sa.id = r.sala_id
             WHERE DATE(CONVERT_TZ(s.slot_inicio, '+00:00', '-03:00')) = :date
             ORDER BY s.slot_inicio, sa.numero"
        );
        $stmt->execute(['date' => $date]);

        return $stmt->fetchAll();
    }

    /** @return array{pending: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>} */
    public function adminReservations(): array
    {
        $this->cleanupExpiredLocks();

        $pendingStmt = $this->pdo->query(
            "SELECT r.public_id AS reserva_id,
                    r.cliente_nome,
                    r.cliente_whatsapp,
                    r.plano,
                    r.status,
                    r.confirm_code,
                    r.locked_until,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), r.locked_until) AS seconds_to_expire,
                    s.public_id AS slot_id,
                    s.slot_inicio,
                    s.slot_fim,
                    sa.numero AS sala_numero,
                    sa.nome AS sala_nome
             FROM reservas r
             INNER JOIN agenda_slots s ON s.id = r.slot_id
             INNER JOIN salas sa ON sa.id = r.sala_id
             WHERE r.status = 'lock_temporario'
               AND r.locked_until > UTC_TIMESTAMP()
             ORDER BY r.created_at DESC"
        );

        $recentStmt = $this->pdo->query(
            "SELECT r.public_id AS reserva_id,
                    r.cliente_nome,
                    r.cliente_whatsapp,
                    r.plano,
                    r.status,
                    r.confirmed_at,
                    s.public_id AS slot_id,
                    s.slot_inicio,
                    s.slot_fim,
                    sa.numero AS sala_numero,
                    sa.nome AS sala_nome
             FROM reservas r
             INNER JOIN agenda_slots s ON s.id = r.slot_id
             INNER JOIN salas sa ON sa.id = r.sala_id
             WHERE r.status <> 'lock_temporario'
             ORDER BY r.updated_at DESC
             LIMIT 20"
        );

        return [
            'pending' => $pendingStmt->fetchAll(),
            'recent' => $recentStmt->fetchAll(),
        ];
    }

    public function blockSlot(string $slotPublicId, string $reason): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE agenda_slots
             SET status = 'bloqueada_admin',
                 lock_token = NULL,
                 locked_until = NULL,
                 bloqueio_motivo = :reason,
                 updated_at = UTC_TIMESTAMP()
             WHERE public_id = :slot_public_id
               AND status <> 'confirmada'
             LIMIT 1"
        );

        $stmt->execute(['slot_public_id' => $slotPublicId, 'reason' => $reason]);

        return $stmt->rowCount() === 1;
    }

    public function unblockSlot(string $slotPublicId): bool
    {
        $reservationStmt = $this->pdo->prepare(
            "UPDATE reservas r
             INNER JOIN agenda_slots s ON s.id = r.slot_id
             SET r.status = 'cancelada',
                 r.cancelled_at = UTC_TIMESTAMP(),
                 r.updated_at = UTC_TIMESTAMP()
             WHERE s.public_id = :slot_public_id
               AND r.status = 'lock_temporario'"
        );
        $reservationStmt->execute(['slot_public_id' => $slotPublicId]);

        $stmt = $this->pdo->prepare(
            "UPDATE agenda_slots
             SET status = 'livre',
                 lock_token = NULL,
                 locked_until = NULL,
                 bloqueio_motivo = NULL,
                 cliente_nome = NULL,
                 cliente_whatsapp = NULL,
                 plano = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE public_id = :slot_public_id
               AND status IN ('lock_temporario', 'bloqueada_admin')
             LIMIT 1"
        );

        $stmt->execute(['slot_public_id' => $slotPublicId]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<string, mixed>|null */
    private function findReservationByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM reservas
             WHERE lock_token = :token
             LIMIT 1"
        );

        $stmt->execute(['token' => $token]);
        $reservation = $stmt->fetch();

        return $reservation ?: null;
    }

    public function generateSlots(string $salaPublicId, string $startDate, string $endDate, array $hours): int
    {
        $roomStmt = $this->pdo->prepare('SELECT id FROM salas WHERE public_id = :public_id AND ativa = 1 LIMIT 1');
        $roomStmt->execute(['public_id' => $salaPublicId]);
        $room = $roomStmt->fetch();

        if (!$room) {
            return 0;
        }

        $inserted = 0;
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO agenda_slots (public_id, sala_id, slot_inicio, slot_fim, status)
             VALUES (UUID(), :sala_id, :slot_inicio, :slot_fim, 'livre')"
        );

        $period = new \DatePeriod(
            new \DateTimeImmutable($startDate),
            new \DateInterval('P1D'),
            (new \DateTimeImmutable($endDate))->modify('+1 day')
        );

        foreach ($period as $day) {
            foreach ($hours as $hour) {
                $local = new \DateTimeImmutable($day->format('Y-m-d') . ' ' . $hour, new \DateTimeZone('America/Sao_Paulo'));
                $utc = $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $utcEnd = $local->modify('+1 hour')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $stmt->execute(['sala_id' => $room['id'], 'slot_inicio' => $utc, 'slot_fim' => $utcEnd]);
                $inserted += $stmt->rowCount();
            }
        }

        return $inserted;
    }

    /**
     * Historico (cadastros finalizados: confirmada, cancelada, expirada), opcionalmente filtrado por nome.
     * @return array<int, array<string, mixed>>
     */
    public function historyReservations(?string $name, int $limit = 500): array
    {
        $limit = max(1, min($limit, 1000));

        $sql = "SELECT r.public_id AS reserva_id,
                       r.cliente_nome,
                       r.cliente_whatsapp,
                       r.plano,
                       r.status,
                       r.confirmed_at,
                       r.cancelled_at,
                       r.created_at,
                       s.public_id AS slot_id,
                       s.slot_inicio,
                       s.slot_fim,
                       sa.numero AS sala_numero,
                       sa.nome AS sala_nome
                FROM reservas r
                INNER JOIN agenda_slots s ON s.id = r.slot_id
                INNER JOIN salas sa ON sa.id = r.sala_id
                WHERE r.status <> 'lock_temporario'";

        $params = [];
        if ($name !== null && $name !== '') {
            $sql .= ' AND r.cliente_nome LIKE :name';
            $params['name'] = '%' . $name . '%';
        }

        $sql .= ' ORDER BY r.updated_at DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Exclui do historico os cadastros informados (nunca os pendentes/ativos).
     * @param array<int, string> $ids
     */
    public function deleteReservationsByPublicIds(array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn ($v): bool => is_string($v) && $v !== ''));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM reservas WHERE public_id IN ({$placeholders}) AND status <> 'lock_temporario'"
        );
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    /** Limpa todo o historico (cadastros finalizados). Preserva os pendentes/ativos. */
    public function deleteAllHistory(): int
    {
        return (int) $this->pdo->exec("DELETE FROM reservas WHERE status <> 'lock_temporario'");
    }
}

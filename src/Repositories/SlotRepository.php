<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SlotRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{expired_reservations: int, released_slots: int} */
    public function cleanupExpiredLocks(): array
    {
        $expiredReservations = (int) $this->pdo->exec(
            "UPDATE reservas
             SET status = 'expirada',
                 updated_at = UTC_TIMESTAMP()
             WHERE status = 'lock_temporario'
               AND locked_until <= UTC_TIMESTAMP()"
        );

        $releasedSlots = (int) $this->pdo->exec(
            "UPDATE agenda_slots
             SET status = 'livre',
                 lock_token = NULL,
                 locked_until = NULL,
                 cliente_nome = NULL,
                 cliente_whatsapp = NULL,
                 plano = NULL,
                 cliente_crp = NULL,
                 publicos_atendidos = NULL,
                 abordagem_trabalho = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE status = 'lock_temporario'
               AND locked_until <= UTC_TIMESTAMP()"
        );

        return [
            'expired_reservations' => $expiredReservations,
            'released_slots' => $releasedSlots,
        ];
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

    /** @return array<int, array<string, mixed>> */
    public function slotsForRange(string $salaPublicId, string $startDate, string $endDate): array
    {
        $this->cleanupExpiredLocks();

        $startUtc = (new \DateTimeImmutable($startDate . ' 00:00:00', new \DateTimeZone('America/Sao_Paulo')))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $endUtc = (new \DateTimeImmutable($endDate . ' 00:00:00', new \DateTimeZone('America/Sao_Paulo')))
            ->modify('+1 day')
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "SELECT s.public_id AS id,
                    DATE(CONVERT_TZ(s.slot_inicio, '+00:00', '-03:00')) AS local_date,
                    s.slot_inicio,
                    s.slot_fim,
                    s.status,
                    s.locked_until,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), s.locked_until) AS seconds_to_unlock
             FROM agenda_slots s
             INNER JOIN salas r ON r.id = s.sala_id
             WHERE r.public_id = :sala_public_id
               AND s.slot_inicio >= :start_utc
               AND s.slot_inicio < :end_utc
             ORDER BY s.slot_inicio"
        );

        $stmt->execute([
            'sala_public_id' => $salaPublicId,
            'start_utc' => $startUtc,
            'end_utc' => $endUtc,
        ]);

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

    /** @param array<int, string> $slotPublicIds
     * @return array<string, mixed>
     */
    public function lockSlots(array $slotPublicIds, string $token, string $confirmCode, int $ttlMinutes, ?string $name, ?string $whatsapp, string $plan, ?string $crp, ?array $publicosAtendidos, ?string $abordagem, string $createdIp, array $aceite): array
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $slotPublicIds = array_values(array_unique($slotPublicIds));
            $idPlaceholders = [];
            $idParams = [];
            foreach ($slotPublicIds as $index => $publicId) {
                $param = ':slot_public_id_' . $index;
                $idPlaceholders[] = $param;
                $idParams[$param] = $publicId;
            }
            $placeholders = implode(',', $idPlaceholders);

            $lookup = $this->pdo->prepare(
                "SELECT s.*, r.public_id AS sala_public_id, r.nome AS sala_nome, r.numero AS sala_numero
                 FROM agenda_slots s
                 INNER JOIN salas r ON r.id = s.sala_id
                 WHERE s.public_id IN ({$placeholders})
                 ORDER BY s.slot_inicio
                 FOR UPDATE"
            );
            $lookup->execute($idParams);
            $slots = $lookup->fetchAll();

            if (count($slots) !== count($slotPublicIds) || !$this->slotsFormValidPackage($slots)) {
                $this->pdo->rollBack();
                return [];
            }

            $stmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'lock_temporario',
                     lock_token = :token,
                     locked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl MINUTE),
                     cliente_nome = :cliente_nome,
                     cliente_whatsapp = :cliente_whatsapp,
                     plano = :plano,
                     cliente_crp = :cliente_crp,
                     publicos_atendidos = :publicos_atendidos,
                     abordagem_trabalho = :abordagem_trabalho,
                     updated_at = UTC_TIMESTAMP()
                 WHERE public_id IN ({$placeholders})
                   AND (
                     status = 'livre'
                     OR (status = 'lock_temporario' AND locked_until <= UTC_TIMESTAMP())
                   )"
            );

            $stmt->bindValue(':token', $token);
            $stmt->bindValue(':ttl', $ttlMinutes, PDO::PARAM_INT);
            $stmt->bindValue(':cliente_nome', $name);
            $stmt->bindValue(':cliente_whatsapp', $whatsapp);
            $stmt->bindValue(':plano', $plan);
            $stmt->bindValue(':cliente_crp', $crp);
            $stmt->bindValue(':publicos_atendidos', $publicosAtendidos !== null ? implode(',', $publicosAtendidos) : null);
            $stmt->bindValue(':abordagem_trabalho', $abordagem);
            foreach ($idParams as $param => $publicId) {
                $stmt->bindValue($param, $publicId);
            }
            $stmt->execute();

            if ($stmt->rowCount() !== count($slotPublicIds)) {
                $this->pdo->rollBack();
                return [];
            }

            $lockedLookup = $this->pdo->prepare(
                "SELECT s.*, r.public_id AS sala_public_id, r.nome AS sala_nome, r.numero AS sala_numero
                 FROM agenda_slots s
                 INNER JOIN salas r ON r.id = s.sala_id
                 WHERE s.lock_token = :token
                 ORDER BY s.slot_inicio"
            );
            $lockedLookup->execute(['token' => $token]);
            $slots = $lockedLookup->fetchAll();

            if (count($slots) !== count($slotPublicIds)) {
                $this->pdo->rollBack();
                return [];
            }

            $firstSlot = $slots[0];
            $lastSlot = $slots[count($slots) - 1];

            $reservationStmt = $this->pdo->prepare(
                "INSERT INTO reservas (
                    public_id,
                    slot_id,
                    sala_id,
                    cliente_nome,
                    cliente_whatsapp,
                    plano,
                    cliente_crp,
                    publicos_atendidos,
                    abordagem_trabalho,
                    status,
                    lock_token,
                    confirm_code,
                    created_ip,
                    aceite_termos,
                    aceite_privacidade,
                    versao_termos,
                    versao_privacidade,
                    data_hora_aceite,
                    origem_aceite,
                    texto_aceite,
                    aceite_user_agent,
                    aceite_ip,
                    locked_until
                 )
                 VALUES (
                    UUID(),
                    :slot_id,
                    :sala_id,
                    :cliente_nome,
                    :cliente_whatsapp,
                    :plano,
                    :cliente_crp,
                    :publicos_atendidos,
                    :abordagem_trabalho,
                    'lock_temporario',
                    :lock_token,
                    :confirm_code,
                    :created_ip,
                    :aceite_termos,
                    :aceite_privacidade,
                    :versao_termos,
                    :versao_privacidade,
                    :data_hora_aceite,
                    :origem_aceite,
                    :texto_aceite,
                    :aceite_user_agent,
                    :aceite_ip,
                    :locked_until
                 )"
            );

            $reservationStmt->execute([
                'slot_id' => $firstSlot['id'],
                'sala_id' => $firstSlot['sala_id'],
                'cliente_nome' => $name,
                'cliente_whatsapp' => $whatsapp,
                'plano' => $plan,
                'cliente_crp' => $crp,
                'publicos_atendidos' => $publicosAtendidos !== null ? implode(',', $publicosAtendidos) : null,
                'abordagem_trabalho' => $abordagem,
                'lock_token' => $token,
                'confirm_code' => $confirmCode,
                'created_ip' => $createdIp !== '' ? $createdIp : null,
                'aceite_termos' => $aceite['aceite_termos'] ? 1 : 0,
                'aceite_privacidade' => $aceite['aceite_privacidade'] ? 1 : 0,
                'versao_termos' => $aceite['versao_termos'],
                'versao_privacidade' => $aceite['versao_privacidade'],
                'data_hora_aceite' => $aceite['data_hora_aceite'],
                'origem_aceite' => $aceite['origem_aceite'],
                'texto_aceite' => $aceite['texto_aceite'],
                'aceite_user_agent' => $aceite['aceite_user_agent'],
                'aceite_ip' => $aceite['aceite_ip'],
                'locked_until' => $firstSlot['locked_until'],
            ]);

            $reservationId = (int) $this->pdo->lastInsertId();
            $linkStmt = $this->pdo->prepare(
                'INSERT INTO reserva_slots (reserva_id, slot_id, ordem) VALUES (:reserva_id, :slot_id, :ordem)'
            );
            foreach ($slots as $index => $slot) {
                $linkStmt->execute([
                    'reserva_id' => $reservationId,
                    'slot_id' => $slot['id'],
                    'ordem' => $index + 1,
                ]);
            }

            $reservation = $this->findReservationByToken($token);
            $this->pdo->commit();

            return [
                ...$firstSlot,
                'slot_inicio' => $firstSlot['slot_inicio'],
                'slot_fim' => $lastSlot['slot_fim'],
                'slot_public_ids' => array_map(static fn (array $slot): string => (string) $slot['public_id'], $slots),
                'duration_slots' => count($slots),
                'reserva_public_id' => $reservation['public_id'] ?? null,
            ];
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function confirmSlot(string $slotPublicId, string $token): bool
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $reservationStmt = $this->pdo->prepare(
                "SELECT id, status, (locked_until > UTC_TIMESTAMP()) AS lock_valido
                 FROM reservas
                 WHERE lock_token = :token
                 LIMIT 1
                 FOR UPDATE"
            );
            $reservationStmt->execute(['token' => $token]);
            $reservation = $reservationStmt->fetch();

            if (!$reservation || $reservation['status'] !== 'lock_temporario' || !(int) $reservation['lock_valido']) {
                $this->pdo->rollBack();
                return false;
            }

            $activeSlots = $this->activeReservationSlotCount((int) $reservation['id']);
            if ($activeSlots < 1) {
                $this->pdo->rollBack();
                return false;
            }

            $stmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE lock_token = :token
                   AND status = 'lock_temporario'
                   AND locked_until > UTC_TIMESTAMP()"
            );

            $stmt->execute(['token' => $token]);

            if ($stmt->rowCount() !== $activeSlots) {
                $this->pdo->rollBack();
                return false;
            }

            $updateReservationStmt = $this->pdo->prepare(
                "UPDATE reservas r
                 SET r.status = 'confirmada',
                     r.payment_status = 'pix_recebido',
                     r.pix_received_at = COALESCE(r.pix_received_at, UTC_TIMESTAMP()),
                     r.locked_until = NULL,
                     r.confirmed_at = UTC_TIMESTAMP(),
                     r.updated_at = UTC_TIMESTAMP()
                 WHERE r.lock_token = :token
                   AND r.status = 'lock_temporario'"
            );
            $updateReservationStmt->execute(['token' => $token]);

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
                        r.lock_token,
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

            $activeSlots = $this->activeReservationSlotCount((int) $reservation['id']);
            if ($activeSlots < 1) {
                $this->pdo->rollBack();
                return 'expired';
            }

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE lock_token = :lock_token
                   AND status = 'lock_temporario'"
            );
            $slotStmt->execute(['lock_token' => $reservation['lock_token']]);

            if ($slotStmt->rowCount() !== $activeSlots) {
                $this->pdo->rollBack();
                return 'expired';
            }

            $reservationStmt = $this->pdo->prepare(
                "UPDATE reservas
                 SET status = 'confirmada',
                     payment_status = 'pix_recebido',
                     pix_received_at = COALESCE(pix_received_at, UTC_TIMESTAMP()),
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

    public function adminMarkPixReceived(string $reservaPublicId): bool
    {
        $this->cleanupExpiredLocks();

        $stmt = $this->pdo->prepare(
            "UPDATE reservas
             SET payment_status = 'pix_recebido',
                 pix_received_at = COALESCE(pix_received_at, UTC_TIMESTAMP()),
                 updated_at = UTC_TIMESTAMP()
             WHERE public_id = :public_id
               AND status = 'lock_temporario'
               AND locked_until > UTC_TIMESTAMP()
             LIMIT 1"
        );
        $stmt->execute(['public_id' => $reservaPublicId]);

        return $stmt->rowCount() === 1;
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
                "SELECT id, slot_id, lock_token, status, (locked_until > UTC_TIMESTAMP()) AS lock_valido
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

            $activeSlots = $this->activeReservationSlotCount((int) $reservation['id']);
            if ($activeSlots < 1) {
                $this->pdo->rollBack();
                return false;
            }

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'confirmada',
                     locked_until = NULL,
                     confirmed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE lock_token = :lock_token
                   AND status = 'lock_temporario'"
            );
            $slotStmt->execute(['lock_token' => $reservation['lock_token']]);

            if ($slotStmt->rowCount() !== $activeSlots) {
                $this->pdo->rollBack();
                return false;
            }

            $reservationStmt = $this->pdo->prepare(
                "UPDATE reservas
                 SET status = 'confirmada',
                     payment_status = 'pix_recebido',
                     pix_received_at = COALESCE(pix_received_at, UTC_TIMESTAMP()),
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
                'SELECT id, slot_id, lock_token, status FROM reservas WHERE public_id = :public_id LIMIT 1 FOR UPDATE'
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

            $reservationSlotsStmt = $this->pdo->prepare(
                "UPDATE reserva_slots
                 SET status = 'cancelada',
                     cancelled_at = UTC_TIMESTAMP()
                 WHERE reserva_id = :reserva_id
                   AND status = 'ativa'"
            );
            $reservationSlotsStmt->execute(['reserva_id' => $reservation['id']]);

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
             SET status = 'livre',
                     lock_token = NULL,
                     locked_until = NULL,
                     confirmed_at = NULL,
                     cliente_nome = NULL,
                     cliente_whatsapp = NULL,
                     plano = NULL,
                     cliente_crp = NULL,
                     publicos_atendidos = NULL,
                     abordagem_trabalho = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE lock_token = :lock_token
                   AND status IN ('lock_temporario', 'confirmada')"
            );
            $slotStmt->execute(['lock_token' => $reservation['lock_token']]);

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * Cancela apenas um horario dentro de um pacote mensal.
     */
    public function adminCancelReservationSlot(string $reservaPublicId, string $slotPublicId): bool
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                "SELECT r.id AS reserva_id,
                        r.status AS reserva_status,
                        rs.id AS reserva_slot_id,
                        s.id AS slot_id
                 FROM reservas r
                 INNER JOIN reserva_slots rs ON rs.reserva_id = r.id
                 INNER JOIN agenda_slots s ON s.id = rs.slot_id
                 WHERE r.public_id = :reserva_public_id
                   AND s.public_id = :slot_public_id
                   AND rs.status = 'ativa'
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([
                'reserva_public_id' => $reservaPublicId,
                'slot_public_id' => $slotPublicId,
            ]);
            $item = $stmt->fetch();

            if (!$item || !in_array($item['reserva_status'], ['lock_temporario', 'confirmada'], true)) {
                $this->pdo->rollBack();
                return false;
            }

            $reservationSlotStmt = $this->pdo->prepare(
                "UPDATE reserva_slots
                 SET status = 'cancelada',
                     cancelled_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $reservationSlotStmt->execute(['id' => $item['reserva_slot_id']]);

            $slotStmt = $this->pdo->prepare(
                "UPDATE agenda_slots
             SET status = 'livre',
                     lock_token = NULL,
                     locked_until = NULL,
                     confirmed_at = NULL,
                     cliente_nome = NULL,
                     cliente_whatsapp = NULL,
                     plano = NULL,
                     cliente_crp = NULL,
                     publicos_atendidos = NULL,
                     abordagem_trabalho = NULL,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :slot_id
                   AND status IN ('lock_temporario', 'confirmada')"
            );
            $slotStmt->execute(['slot_id' => $item['slot_id']]);

            $countStmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS active_slots
                 FROM reserva_slots
                 WHERE reserva_id = :reserva_id
                   AND status = 'ativa'"
            );
            $countStmt->execute(['reserva_id' => $item['reserva_id']]);
            $activeSlots = (int) ($countStmt->fetch()['active_slots'] ?? 0);

            if ($activeSlots === 0) {
                $reservationStmt = $this->pdo->prepare(
                    "UPDATE reservas
                     SET status = 'cancelada',
                         cancelled_at = UTC_TIMESTAMP(),
                         locked_until = NULL,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = :id"
                );
                $reservationStmt->execute(['id' => $item['reserva_id']]);
            } else {
                $reservationStmt = $this->pdo->prepare(
                    'UPDATE reservas SET updated_at = UTC_TIMESTAMP() WHERE id = :id'
                );
                $reservationStmt->execute(['id' => $item['reserva_id']]);
            }

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
    public function adminUpdateReservation(string $reservaPublicId, ?string $name, ?string $whatsapp, ?string $plan, ?string $crp, ?array $publicosAtendidos, ?string $abordagem): bool
    {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, slot_id, lock_token, status, cliente_nome, cliente_whatsapp, plano, cliente_crp, publicos_atendidos, abordagem_trabalho
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
                'cliente_crp' => $crp ?? $reservation['cliente_crp'],
                'publicos_atendidos' => $publicosAtendidos !== null ? implode(',', $publicosAtendidos) : $reservation['publicos_atendidos'],
                'abordagem_trabalho' => $abordagem ?? $reservation['abordagem_trabalho'],
            ];

            $reservationStmt = $this->pdo->prepare(
                'UPDATE reservas
                 SET cliente_nome = :cliente_nome,
                     cliente_whatsapp = :cliente_whatsapp,
                     plano = :plano,
                     cliente_crp = :cliente_crp,
                     publicos_atendidos = :publicos_atendidos,
                     abordagem_trabalho = :abordagem_trabalho,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $reservationStmt->execute([...$values, 'id' => $reservation['id']]);

            $slotStmt = $this->pdo->prepare(
                'UPDATE agenda_slots
                 SET cliente_nome = :cliente_nome,
                     cliente_whatsapp = :cliente_whatsapp,
                     plano = :plano,
                     cliente_crp = :cliente_crp,
                     publicos_atendidos = :publicos_atendidos,
                     abordagem_trabalho = :abordagem_trabalho,
                     updated_at = UTC_TIMESTAMP()
                 WHERE lock_token = :lock_token'
            );
            $slotStmt->execute([...$values, 'lock_token' => $reservation['lock_token']]);

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
                    r.cliente_crp,
                    r.publicos_atendidos,
                    r.abordagem_trabalho,
                    r.status,
                    r.payment_status,
                    r.pix_received_at,
                    r.created_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(s.public_id ORDER BY rs.ordem), ',', 1) AS slot_id,
                    MIN(s.slot_inicio) AS slot_inicio,
                    MAX(s.slot_fim) AS slot_fim,
                    COUNT(*) AS duration_slots,
                    GROUP_CONCAT(CONCAT(s.public_id, '|', s.slot_inicio, '|', s.slot_fim, '|', rs.status) ORDER BY s.slot_inicio SEPARATOR '||') AS slot_items_raw,
                    sa.numero AS sala_numero,
                    sa.nome AS sala_nome
             FROM reservas r
             INNER JOIN reserva_slots rs ON rs.reserva_id = r.id
             INNER JOIN agenda_slots s ON s.id = rs.slot_id
             INNER JOIN salas sa ON sa.id = r.sala_id
             WHERE DATE(CONVERT_TZ(s.slot_inicio, '+00:00', '-03:00')) = :date
               AND rs.status = 'ativa'
             GROUP BY r.id, r.public_id, r.cliente_nome, r.cliente_whatsapp, r.plano, r.cliente_crp, r.publicos_atendidos, r.abordagem_trabalho, r.status, r.payment_status, r.pix_received_at, r.created_at, sa.numero, sa.nome
             ORDER BY slot_inicio, sa.numero"
        );
        $stmt->execute(['date' => $date]);

        return $this->hydrateReservationRows($stmt->fetchAll());
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
                    r.cliente_crp,
                    r.publicos_atendidos,
                    r.abordagem_trabalho,
                    r.status,
                    r.payment_status,
                    r.pix_received_at,
                    r.confirm_code,
                    r.locked_until,
                    TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), r.locked_until) AS seconds_to_expire,
                    SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN rs.status = 'ativa' THEN s.public_id END ORDER BY s.slot_inicio), ',', 1) AS slot_id,
                    COALESCE(MIN(CASE WHEN rs.status = 'ativa' THEN s.slot_inicio END), MIN(s.slot_inicio)) AS slot_inicio,
                    COALESCE(MAX(CASE WHEN rs.status = 'ativa' THEN s.slot_fim END), MAX(s.slot_fim)) AS slot_fim,
                    SUM(CASE WHEN rs.status = 'ativa' THEN 1 ELSE 0 END) AS duration_slots,
                    GROUP_CONCAT(CONCAT(s.public_id, '|', s.slot_inicio, '|', s.slot_fim, '|', rs.status) ORDER BY s.slot_inicio SEPARATOR '||') AS slot_items_raw,
                    sa.numero AS sala_numero,
                    sa.nome AS sala_nome
             FROM reservas r
             INNER JOIN reserva_slots rs ON rs.reserva_id = r.id
             INNER JOIN agenda_slots s ON s.id = rs.slot_id
             INNER JOIN salas sa ON sa.id = r.sala_id
             WHERE r.status = 'lock_temporario'
               AND r.locked_until > UTC_TIMESTAMP()
             GROUP BY r.id, r.public_id, r.cliente_nome, r.cliente_whatsapp, r.plano, r.cliente_crp, r.publicos_atendidos, r.abordagem_trabalho, r.status, r.payment_status, r.pix_received_at, r.confirm_code, r.locked_until, r.created_at, sa.numero, sa.nome
             ORDER BY r.created_at DESC"
        );

        $recentStmt = $this->pdo->query(
            "SELECT r.public_id AS reserva_id,
                    r.cliente_nome,
                    r.cliente_whatsapp,
                    r.plano,
                    r.cliente_crp,
                    r.publicos_atendidos,
                    r.abordagem_trabalho,
                    r.status,
                    r.payment_status,
                    r.pix_received_at,
                    r.confirmed_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN rs.status = 'ativa' THEN s.public_id END ORDER BY s.slot_inicio), ',', 1) AS slot_id,
                    COALESCE(MIN(CASE WHEN rs.status = 'ativa' THEN s.slot_inicio END), MIN(s.slot_inicio)) AS slot_inicio,
                    COALESCE(MAX(CASE WHEN rs.status = 'ativa' THEN s.slot_fim END), MAX(s.slot_fim)) AS slot_fim,
                    SUM(CASE WHEN rs.status = 'ativa' THEN 1 ELSE 0 END) AS duration_slots,
                    GROUP_CONCAT(CONCAT(s.public_id, '|', s.slot_inicio, '|', s.slot_fim, '|', rs.status) ORDER BY s.slot_inicio SEPARATOR '||') AS slot_items_raw,
                    sa.numero AS sala_numero,
                    sa.nome AS sala_nome
             FROM reservas r
             INNER JOIN reserva_slots rs ON rs.reserva_id = r.id
             INNER JOIN agenda_slots s ON s.id = rs.slot_id
             INNER JOIN salas sa ON sa.id = r.sala_id
             WHERE r.status <> 'lock_temporario'
             GROUP BY r.id, r.public_id, r.cliente_nome, r.cliente_whatsapp, r.plano, r.cliente_crp, r.publicos_atendidos, r.abordagem_trabalho, r.status, r.payment_status, r.pix_received_at, r.confirmed_at, r.updated_at, sa.numero, sa.nome
             ORDER BY r.updated_at DESC
             LIMIT 20"
        );

        return [
            'pending' => $this->hydrateReservationRows($pendingStmt->fetchAll()),
            'recent' => $this->hydrateReservationRows($recentStmt->fetchAll()),
        ];
    }

    public function blockSlot(string $slotPublicId, string $reason): bool
    {
        $this->cleanupExpiredLocks();
        $this->pdo->beginTransaction();

        try {
            $slotStmt = $this->pdo->prepare(
                "SELECT id, status, lock_token
                 FROM agenda_slots
                 WHERE public_id = :slot_public_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $slotStmt->execute(['slot_public_id' => $slotPublicId]);
            $slot = $slotStmt->fetch();

            if (!$slot || $slot['status'] === 'confirmada') {
                $this->pdo->rollBack();
                return false;
            }

            if ($slot['status'] === 'lock_temporario' && $slot['lock_token']) {
                $reservationStmt = $this->pdo->prepare(
                    "SELECT id, lock_token
                     FROM reservas
                     WHERE lock_token = :lock_token
                       AND status = 'lock_temporario'
                     FOR UPDATE"
                );
                $reservationStmt->execute(['lock_token' => $slot['lock_token']]);
                $reservations = $reservationStmt->fetchAll();

                if ($reservations !== []) {
                    $reservationIds = array_map(static fn (array $reservation): int => (int) $reservation['id'], $reservations);
                    $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));

                    $cancelReservationsStmt = $this->pdo->prepare(
                        "UPDATE reservas
                         SET status = 'cancelada',
                             cancelled_at = UTC_TIMESTAMP(),
                             locked_until = NULL,
                             updated_at = UTC_TIMESTAMP()
                         WHERE id IN ({$placeholders})"
                    );
                    $cancelReservationsStmt->execute($reservationIds);

                    $cancelLinksStmt = $this->pdo->prepare(
                        "UPDATE reserva_slots
                         SET status = 'cancelada',
                             cancelled_at = UTC_TIMESTAMP()
                         WHERE reserva_id IN ({$placeholders})
                           AND status = 'ativa'"
                    );
                    $cancelLinksStmt->execute($reservationIds);
                }

                $releasePackageStmt = $this->pdo->prepare(
                    "UPDATE agenda_slots
                     SET status = 'livre',
                         lock_token = NULL,
                         locked_until = NULL,
                         confirmed_at = NULL,
                         cliente_nome = NULL,
                         cliente_whatsapp = NULL,
                         plano = NULL,
                         cliente_crp = NULL,
                         publicos_atendidos = NULL,
                         abordagem_trabalho = NULL,
                         updated_at = UTC_TIMESTAMP()
                     WHERE lock_token = :lock_token
                       AND status = 'lock_temporario'
                       AND id <> :slot_id"
                );
                $releasePackageStmt->execute([
                    'lock_token' => $slot['lock_token'],
                    'slot_id' => $slot['id'],
                ]);
            }

            $stmt = $this->pdo->prepare(
                "UPDATE agenda_slots
                 SET status = 'bloqueada_admin',
                     lock_token = NULL,
                     locked_until = NULL,
                     confirmed_at = NULL,
                     cliente_nome = NULL,
                     cliente_whatsapp = NULL,
                     plano = NULL,
                     cliente_crp = NULL,
                     publicos_atendidos = NULL,
                     abordagem_trabalho = NULL,
                     bloqueio_motivo = :reason,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :slot_id
                   AND status <> 'confirmada'
                 LIMIT 1"
            );

            $stmt->execute(['slot_id' => $slot['id'], 'reason' => $reason]);

            if ($stmt->rowCount() !== 1) {
                $this->pdo->rollBack();
                return false;
            }

            $this->pdo->commit();

            return true;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
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
                 cliente_crp = NULL,
                 publicos_atendidos = NULL,
                 abordagem_trabalho = NULL,
                 updated_at = UTC_TIMESTAMP()
             WHERE public_id = :slot_public_id
               AND status IN ('lock_temporario', 'bloqueada_admin')
             LIMIT 1"
        );

        $stmt->execute(['slot_public_id' => $slotPublicId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Pacotes mensais aceitam horarios nao consecutivos, mas sempre na mesma sala,
     * no mesmo mes local e somente com slots ainda livres.
     *
     * @param array<int, array<string, mixed>> $slots
     */
    private function slotsFormValidPackage(array $slots): bool
    {
        if ($slots === []) {
            return false;
        }

        $roomId = (int) $slots[0]['sala_id'];
        $localMonth = $this->localMonth((string) $slots[0]['slot_inicio']);

        foreach ($slots as $slot) {
            if ((int) $slot['sala_id'] !== $roomId) {
                return false;
            }

            if ($slot['status'] !== 'livre') {
                return false;
            }

            if ($this->localMonth((string) $slot['slot_inicio']) !== $localMonth) {
                return false;
            }
        }

        return true;
    }

    private function localMonth(string $utcDateTime): string
    {
        return (new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('America/Sao_Paulo'))
            ->format('Y-m');
    }

    private function activeReservationSlotCount(int $reservationId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total
             FROM reserva_slots rs
             INNER JOIN agenda_slots s ON s.id = rs.slot_id
             WHERE rs.reserva_id = :reservation_id
               AND rs.status = 'ativa'
               AND s.status = 'lock_temporario'
               AND s.locked_until > UTC_TIMESTAMP()"
        );
        $stmt->execute(['reservation_id' => $reservationId]);

        return (int) ($stmt->fetch()['total'] ?? 0);
    }

    /** @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function hydrateReservationRows(array $rows): array
    {
        return array_map(function (array $row): array {
            $row['slot_items'] = $this->parseSlotItems((string) ($row['slot_items_raw'] ?? ''));
            unset($row['slot_items_raw']);

            return $row;
        }, $rows);
    }

    /** @return array<int, array{slot_id: string, slot_inicio: string, slot_fim: string, status: string}> */
    private function parseSlotItems(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $items = [];
        foreach (explode('||', $raw) as $item) {
            [$slotId, $start, $end, $status] = array_pad(explode('|', $item), 4, '');
            if ($slotId === '' || $start === '' || $end === '') {
                continue;
            }

            $items[] = [
                'slot_id' => $slotId,
                'slot_inicio' => $start,
                'slot_fim' => $end,
                'status' => $status !== '' ? $status : 'ativa',
            ];
        }

        return $items;
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
                       r.cliente_crp,
                       r.publicos_atendidos,
                       r.abordagem_trabalho,
                       r.status,
                       r.payment_status,
                       r.pix_received_at,
                       r.confirmed_at,
                       r.cancelled_at,
                       r.created_at,
                       SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN rs.status = 'ativa' THEN s.public_id END ORDER BY s.slot_inicio), ',', 1) AS slot_id,
                       COALESCE(MIN(CASE WHEN rs.status = 'ativa' THEN s.slot_inicio END), MIN(s.slot_inicio)) AS slot_inicio,
                       COALESCE(MAX(CASE WHEN rs.status = 'ativa' THEN s.slot_fim END), MAX(s.slot_fim)) AS slot_fim,
                       SUM(CASE WHEN rs.status = 'ativa' THEN 1 ELSE 0 END) AS duration_slots,
                       GROUP_CONCAT(CONCAT(s.public_id, '|', s.slot_inicio, '|', s.slot_fim, '|', rs.status) ORDER BY s.slot_inicio SEPARATOR '||') AS slot_items_raw,
                       sa.numero AS sala_numero,
                       sa.nome AS sala_nome
                FROM reservas r
                INNER JOIN reserva_slots rs ON rs.reserva_id = r.id
                INNER JOIN agenda_slots s ON s.id = rs.slot_id
                INNER JOIN salas sa ON sa.id = r.sala_id
                WHERE r.status <> 'lock_temporario'";

        $params = [];
        if ($name !== null && $name !== '') {
            $sql .= ' AND r.cliente_nome LIKE :name';
            $params['name'] = '%' . $name . '%';
        }

        $sql .= ' GROUP BY r.id, r.public_id, r.cliente_nome, r.cliente_whatsapp, r.plano, r.cliente_crp, r.publicos_atendidos, r.abordagem_trabalho, r.status, r.payment_status, r.pix_received_at, r.confirmed_at, r.cancelled_at, r.created_at, r.updated_at, sa.numero, sa.nome ORDER BY r.updated_at DESC LIMIT ' . $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $this->hydrateReservationRows($stmt->fetchAll());
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

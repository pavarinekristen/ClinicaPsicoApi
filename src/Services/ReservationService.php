<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppException;
use App\Repositories\SlotRepository;

final class ReservationService
{
    public function __construct(private readonly SlotRepository $slots, private readonly int $lockTtlMinutes)
    {
    }

    private const MAX_ACTIVE_LOCKS_PER_IP = 3;

    /** @param array<int, string> $slotIds
     * @return array<string, mixed>
     */
    /**
     * @param array<int, string> $publicosAtendidos
     * @return array<string, mixed>
     */
    public function lock(array $slotIds, ?string $name, ?string $whatsapp, string $plan, ?string $crp, ?array $publicosAtendidos, ?string $abordagem, string $ip): array
    {
        $slotIds = array_values(array_unique(array_filter($slotIds, static fn ($id): bool => is_string($id) && $id !== '')));
        if ($slotIds === []) {
            throw new AppException('Selecione ao menos um horario.', 422);
        }

        $this->assertPlanDuration($plan, count($slotIds));

        if ($ip !== '' && $this->slots->activeLocksForIp($ip) >= self::MAX_ACTIVE_LOCKS_PER_IP) {
            throw new AppException('Limite de pre-reservas simultaneas atingido. Aguarde uma expirar ou fale com a equipe.', 429);
        }

        $token = bin2hex(random_bytes(32));
        $confirmCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $slot = $this->slots->lockSlots($slotIds, $token, $confirmCode, $this->lockTtlMinutes, $name, $whatsapp, $plan, $crp, $publicosAtendidos, $abordagem, $ip);

        if ($slot === []) {
            throw new AppException('Horario indisponivel para a duracao escolhida. Atualize o calendario.', 409);
        }

        return [
            'reserva_id' => $slot['reserva_public_id'],
            'slot_id' => $slot['public_id'],
            'slot_ids' => $slot['slot_public_ids'],
            'lock_token' => $token,
            'locked_until' => $slot['locked_until'],
            'sala' => [
                'id' => $slot['sala_public_id'],
                'numero' => $slot['sala_numero'],
                'nome' => $slot['sala_nome'],
            ],
            'inicio' => $slot['slot_inicio'],
            'fim' => $slot['slot_fim'],
            'status' => $slot['status'],
            'duration_slots' => $slot['duration_slots'],
        ];
    }

    public function confirm(string $slotId, string $token): void
    {
        if (!$this->slots->confirmSlot($slotId, $token)) {
            throw new AppException('Lock invalido, expirado ou ja confirmado.', 409);
        }
    }

    public function confirmByCode(string $reservaId, string $code): void
    {
        $result = $this->slots->confirmReservationByCode($reservaId, $code);

        match ($result) {
            'ok', 'already_confirmed' => null,
            'not_found' => throw new AppException('Reserva nao encontrada.', 404),
            'expired' => throw new AppException('Reserva expirada ou cancelada. Faca um novo cadastro.', 409),
            'blocked' => throw new AppException('Muitas tentativas de codigo. Fale com a equipe pelo WhatsApp.', 429),
            default => throw new AppException('Codigo de confirmacao invalido.', 422),
        };
    }

    public function adminConfirm(string $reservaId): void
    {
        if (!$this->slots->adminConfirmReservation($reservaId)) {
            throw new AppException('Reserva nao encontrada, expirada ou ja finalizada.', 409);
        }
    }

    public function adminCancel(string $reservaId): void
    {
        if (!$this->slots->adminCancelReservation($reservaId)) {
            throw new AppException('Reserva nao encontrada ou ja finalizada.', 409);
        }
    }

    public function adminCancelSlot(string $reservaId, string $slotId): void
    {
        if (!$this->slots->adminCancelReservationSlot($reservaId, $slotId)) {
            throw new AppException('Horario nao encontrado ou ja cancelado.', 409);
        }
    }

    /**
     * @param array<int, string>|null $publicosAtendidos
     */
    public function adminUpdate(string $reservaId, ?string $name, ?string $whatsapp, ?string $plan, ?string $crp, ?array $publicosAtendidos, ?string $abordagem): void
    {
        if ($name === null && $whatsapp === null && $plan === null && $crp === null && $publicosAtendidos === null && $abordagem === null) {
            throw new AppException('Nada para atualizar.', 422);
        }

        if (!$this->slots->adminUpdateReservation($reservaId, $name, $whatsapp, $plan, $crp, $publicosAtendidos, $abordagem)) {
            throw new AppException('Reserva nao encontrada ou ja finalizada.', 409);
        }
    }

    /** @return array{pending: array<int, array<string, mixed>>, recent: array<int, array<string, mixed>>} */
    public function adminReservations(): array
    {
        return $this->slots->adminReservations();
    }

    /** @return array<int, array<string, mixed>> */
    public function adminReservationsForDay(string $date): array
    {
        return $this->slots->reservationsForDay($date);
    }

    public function block(string $slotId, string $reason): void
    {
        if (!$this->slots->blockSlot($slotId, $reason)) {
            throw new AppException('Nao foi possivel bloquear o horario.', 409);
        }
    }

    public function unblock(string $slotId): void
    {
        if (!$this->slots->unblockSlot($slotId)) {
            throw new AppException('Nao foi possivel liberar o horario.', 409);
        }
    }

    public function generateSlots(string $salaId, string $startDate, string $endDate, array $hours): int
    {
        return $this->slots->generateSlots($salaId, $startDate, $endDate, $hours);
    }

    /** @return array<int, array<string, mixed>> */
    public function history(?string $name): array
    {
        return $this->slots->historyReservations($name, 500);
    }

    /** @param array<int, string> $ids */
    public function deleteFromHistory(array $ids): int
    {
        return $this->slots->deleteReservationsByPublicIds($ids);
    }

    public function clearHistory(): int
    {
        return $this->slots->deleteAllHistory();
    }

    private function assertPlanDuration(string $plan, int $slots): void
    {
        $ranges = [
            'Light - Hora avulsa' => [1, 1],
            'Standard - 2 a 4 horas' => [2, 4],
            'Full - 5 a 8 horas' => [5, 8],
            'Premium - acima de 9 horas' => [9, 12],
        ];

        if (!isset($ranges[$plan])) {
            throw new AppException('Esse plano precisa ser combinado diretamente com a equipe pelo WhatsApp.', 422);
        }

        [$min, $max] = $ranges[$plan];
        if ($slots < $min || $slots > $max) {
            throw new AppException("O plano selecionado exige entre {$min} e {$max} hora(s).", 422);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SlotRepository;

final class AvailabilityService
{
    public function __construct(private readonly SlotRepository $slots)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function forDay(string $salaId, string $date): array
    {
        return array_map(static function (array $slot): array {
            $available = $slot['status'] === 'livre';

            return [
                'id' => $slot['id'],
                'inicio' => $slot['slot_inicio'],
                'fim' => $slot['slot_fim'],
                'status' => $slot['status'],
                'available' => $available,
                'blocked' => !$available,
                'color' => $available ? 'green' : 'red',
                'label' => $available ? 'Livre' : 'Bloqueado',
                'locked_until' => $slot['locked_until'],
                'seconds_to_unlock' => max(0, (int) ($slot['seconds_to_unlock'] ?? 0)),
                'cliente_nome' => $slot['cliente_nome'] ?? null,
                'cliente_whatsapp' => $slot['cliente_whatsapp'] ?? null,
                'cliente_crp' => $slot['cliente_crp'] ?? null,
                'publicos_atendidos' => $slot['publicos_atendidos'] ?? null,
                'abordagem_trabalho' => $slot['abordagem_trabalho'] ?? null,
            ];
        }, $this->slots->slotsForDay($salaId, $date));
    }
}

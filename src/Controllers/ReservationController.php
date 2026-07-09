<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ReservationService;

final class ReservationController
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function lock(Request $request): never
    {
        $slotIdsInput = $request->input('slot_ids');
        if (is_array($slotIdsInput)) {
            $slotIds = array_values(array_unique(array_map(
                static fn ($value): string => Validator::requiredString($value, 'slot_ids', 36),
                $slotIdsInput
            )));
        } else {
            $slotIds = [Validator::requiredString($request->input('slot_id'), 'slot_id', 36)];
        }

        $name = Validator::optionalString($request->input('cliente_nome'), 'cliente_nome', 160);
        $whatsapp = Validator::optionalString($request->input('cliente_whatsapp'), 'cliente_whatsapp', 32);
        $plan = Validator::requiredString($request->input('plano'), 'plano', 80);
        $crp = Validator::optionalString($request->input('cliente_crp'), 'cliente_crp', 32);
        $abordagem = Validator::optionalString($request->input('abordagem_trabalho'), 'abordagem_trabalho', 120);
        $publicosAtendidos = $this->normalizePublicosAtendidos($request->input('publicos_atendidos'));

        Response::ok($this->reservations->lock($slotIds, $name, $whatsapp, $plan, $crp, $publicosAtendidos, $abordagem, $request->ip()));
    }

    public function confirm(Request $request): never
    {
        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);
        $code = Validator::requiredString($request->input('codigo'), 'codigo', 6);

        if (!preg_match('/^\d{6}$/', $code)) {
            throw new AppException('Codigo de confirmacao invalido.', 422);
        }

        $this->reservations->confirmByCode($reservaId, $code);
        Response::ok(['confirmed' => true]);
    }

    /** @return array<int, string>|null */
    private function normalizePublicosAtendidos(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $items = array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
            return $items === [] ? null : $items;
        }

        if (!is_array($value)) {
            throw new AppException('Campo invalido: publicos_atendidos.', 422);
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new AppException('Campo invalido: publicos_atendidos.', 422);
            }

            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items === [] ? null : array_values(array_unique($items));
    }
}

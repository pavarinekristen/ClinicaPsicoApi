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

        Response::ok($this->reservations->lock($slotIds, $name, $whatsapp, $plan, $request->ip()));
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
}

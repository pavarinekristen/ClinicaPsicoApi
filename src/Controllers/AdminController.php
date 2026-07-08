<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ReservationService;

final class AdminController
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function generateSlots(Request $request): never
    {
        $this->authorize($request);

        $salaId = Validator::requiredString($request->input('sala_id'), 'sala_id', 36);
        $startDate = Validator::dateYmd($request->input('start_date'), 'start_date');
        $endDate = Validator::dateYmd($request->input('end_date'), 'end_date');
        $hours = $request->input('hours', ['08:00', '09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00']);

        if (!is_array($hours) || $hours === []) {
            throw new AppException('Lista de horarios invalida.', 422);
        }

        foreach ($hours as $hour) {
            if (!is_string($hour) || !preg_match('/^\d{2}:\d{2}$/', $hour)) {
                throw new AppException('Horario invalido em hours.', 422);
            }
        }

        Response::ok(['inserted' => $this->reservations->generateSlots($salaId, $startDate, $endDate, $hours)]);
    }

    public function listReservations(Request $request): never
    {
        $this->authorize($request);

        Response::ok($this->reservations->adminReservations());
    }

    public function reservationsByDay(Request $request): never
    {
        $this->authorize($request);

        $date = Validator::dateYmd($request->query('date'), 'date');

        Response::ok([
            'date' => $date,
            'reservations' => $this->reservations->adminReservationsForDay($date),
        ]);
    }

    public function confirmReservationById(Request $request): never
    {
        $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);

        $this->reservations->adminConfirm($reservaId);
        Response::ok(['confirmed' => true]);
    }

    public function cancelReservation(Request $request): never
    {
        $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);

        $this->reservations->adminCancel($reservaId);
        Response::ok(['cancelled' => true]);
    }

    public function updateReservation(Request $request): never
    {
        $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);
        $name = Validator::optionalString($request->input('cliente_nome'), 'cliente_nome', 160);
        $whatsapp = Validator::optionalString($request->input('cliente_whatsapp'), 'cliente_whatsapp', 32);
        $plan = Validator::optionalString($request->input('plano'), 'plano', 80);

        $this->reservations->adminUpdate($reservaId, $name, $whatsapp, $plan);
        Response::ok(['updated' => true]);
    }

    public function confirmReservation(Request $request): never
    {
        $this->authorize($request);

        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);
        $token = Validator::requiredString($request->input('lock_token'), 'lock_token', 64);

        $this->reservations->confirm($slotId, $token);
        Response::ok(['confirmed' => true]);
    }

    public function blockSlot(Request $request): never
    {
        $this->authorize($request);

        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);
        $reason = Validator::requiredString($request->input('reason'), 'reason', 255);

        $this->reservations->block($slotId, $reason);
        Response::ok(['blocked' => true]);
    }

    public function unblockSlot(Request $request): never
    {
        $this->authorize($request);

        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);

        $this->reservations->unblock($slotId);
        Response::ok(['unblocked' => true]);
    }

    private function authorize(Request $request): void
    {
        $actual = $request->header('x-admin-token');

        if ($actual === null || $actual === '' || !$this->tokenIsValid($actual)) {
            usleep(350000); // desacelera forca bruta no token
            throw new AppException('Nao autorizado.', 401);
        }
    }

    private function tokenIsValid(string $actual): bool
    {
        // Modo recomendado: somente o hash SHA-256 do token fica no .env.
        // Quem ler o arquivo nao descobre a senha (hash nao e reversivel).
        $expectedHash = strtolower(trim((string) Env::get('ADMIN_TOKEN_HASH', '')));

        if ($expectedHash !== '') {
            if (!preg_match('/^[0-9a-f]{64}$/', $expectedHash)) {
                return false; // hash malformado: falha fechado
            }

            return hash_equals($expectedHash, hash('sha256', $actual));
        }

        // Modo legado (dev local): token em texto puro no .env.
        $expected = Env::get('ADMIN_TOKEN', '') ?? '';
        $isProduction = Env::get('APP_ENV', 'production') === 'production';

        $misconfigured = $expected === ''
            || $expected === 'troque-este-token'
            || ($isProduction && strlen($expected) < 16);

        return !$misconfigured && hash_equals($expected, $actual);
    }
}
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\AuditRepository;
use App\Services\AuthService;
use App\Services\ReservationService;

final class AdminController
{
    private const MAX_AUTH_ATTEMPTS = 10;
    private const AUTH_WINDOW_SECONDS = 900; // 15 minutos

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly AuthService $auth,
        private readonly AuditRepository $audit
    ) {
    }

    public function generateSlots(Request $request): never
    {
        $user = $this->authorize($request);

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

        $inserted = $this->reservations->generateSlots($salaId, $startDate, $endDate, $hours);
        $this->audit->record($user, 'slots_gerados', 'sala', $salaId, ['inserted' => $inserted, 'de' => $startDate, 'ate' => $endDate], $request->ip());
        Response::ok(['inserted' => $inserted]);
    }

    public function listReservations(Request $request): never
    {
        // Leitura: nao auditada (o painel consulta a cada 15s).
        $this->authorize($request);

        Response::ok($this->reservations->adminReservations());
    }

    public function reservationsByDay(Request $request): never
    {
        // Leitura: nao auditada (o painel consulta a cada 15s).
        $this->authorize($request);

        $date = Validator::dateYmd($request->query('date'), 'date');

        Response::ok([
            'date' => $date,
            'reservations' => $this->reservations->adminReservationsForDay($date),
        ]);
    }

    public function history(Request $request): never
    {
        // Leitura: nao auditada.
        $this->authorize($request);

        $name = Validator::optionalString($request->query('q'), 'q', 160);

        Response::ok(['reservations' => $this->reservations->history($name)]);
    }

    public function deleteReservations(Request $request): never
    {
        $user = $this->authorize($request);

        $ids = $request->input('reserva_ids');
        if (!is_array($ids)) {
            throw new AppException('Selecione ao menos um cadastro.', 422);
        }

        $ids = array_values(array_filter(
            array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $ids),
            static fn (string $v): bool => $v !== ''
        ));

        if ($ids === []) {
            throw new AppException('Selecione ao menos um cadastro.', 422);
        }

        $deleted = $this->reservations->deleteFromHistory($ids);
        $this->audit->record($user, 'historico_excluido', 'reserva', null, ['quantidade' => $deleted], $request->ip());
        Response::ok(['deleted' => $deleted]);
    }

    public function deleteAllHistory(Request $request): never
    {
        $user = $this->authorize($request);

        $deleted = $this->reservations->clearHistory();
        $this->audit->record($user, 'historico_limpo', 'reserva', null, ['quantidade' => $deleted], $request->ip());
        Response::ok(['deleted' => $deleted]);
    }

    public function confirmReservationById(Request $request): never
    {
        $user = $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);

        $this->reservations->adminConfirm($reservaId);
        $this->audit->record($user, 'reserva_confirmada_manual', 'reserva', $reservaId, [], $request->ip());
        Response::ok(['confirmed' => true]);
    }

    public function cancelReservation(Request $request): never
    {
        $user = $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);

        $this->reservations->adminCancel($reservaId);
        $this->audit->record($user, 'reserva_cancelada', 'reserva', $reservaId, [], $request->ip());
        Response::ok(['cancelled' => true]);
    }

    public function cancelReservationSlot(Request $request): never
    {
        $user = $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);
        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);

        $this->reservations->adminCancelSlot($reservaId, $slotId);
        $this->audit->record($user, 'reserva_horario_cancelado', 'reserva', $reservaId, ['slot_id' => $slotId], $request->ip());
        Response::ok(['cancelled' => true]);
    }

    public function updateReservation(Request $request): never
    {
        $user = $this->authorize($request);

        $reservaId = Validator::requiredString($request->input('reserva_id'), 'reserva_id', 36);
        $name = Validator::optionalString($request->input('cliente_nome'), 'cliente_nome', 160);
        $whatsapp = Validator::optionalString($request->input('cliente_whatsapp'), 'cliente_whatsapp', 32);
        $plan = Validator::optionalString($request->input('plano'), 'plano', 80);

        $this->reservations->adminUpdate($reservaId, $name, $whatsapp, $plan);

        // Registra quais campos mudaram, sem gravar os valores (evita duplicar PII no log).
        $changed = [];
        if ($name !== null) {
            $changed[] = 'cliente_nome';
        }
        if ($whatsapp !== null) {
            $changed[] = 'cliente_whatsapp';
        }
        if ($plan !== null) {
            $changed[] = 'plano';
        }

        $this->audit->record($user, 'reserva_editada', 'reserva', $reservaId, ['campos' => $changed], $request->ip());
        Response::ok(['updated' => true]);
    }

    public function confirmReservation(Request $request): never
    {
        $user = $this->authorize($request);

        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);
        $token = Validator::requiredString($request->input('lock_token'), 'lock_token', 64);

        $this->reservations->confirm($slotId, $token);
        $this->audit->record($user, 'slot_confirmado', 'slot', $slotId, [], $request->ip());
        Response::ok(['confirmed' => true]);
    }

    public function blockSlot(Request $request): never
    {
        $user = $this->authorize($request);

        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);
        $reason = Validator::requiredString($request->input('reason'), 'reason', 255);

        $this->reservations->block($slotId, $reason);
        $this->audit->record($user, 'slot_bloqueado', 'slot', $slotId, ['motivo' => $reason], $request->ip());
        Response::ok(['blocked' => true]);
    }

    public function unblockSlot(Request $request): never
    {
        $user = $this->authorize($request);

        $slotId = Validator::requiredString($request->input('slot_id'), 'slot_id', 36);

        $this->reservations->unblock($slotId);
        $this->audit->record($user, 'slot_desbloqueado', 'slot', $slotId, [], $request->ip());
        Response::ok(['unblocked' => true]);
    }

    /** Valida o token de sessao (Bearer) e devolve o usuario autenticado. */
    private function authorize(Request $request): string
    {
        $limiter = new RateLimiter(dirname(__DIR__, 2) . '/storage/cache/ratelimit');
        $ip = $request->ip();
        $key = 'admin-auth:' . ($ip !== '' ? $ip : 'unknown');

        // Lockout por IP: freia brute force online contra a rota administrativa.
        if ($limiter->tooManyAttempts($key, self::MAX_AUTH_ATTEMPTS, self::AUTH_WINDOW_SECONDS)) {
            throw new AppException('Muitas tentativas de acesso. Aguarde alguns minutos e tente de novo.', 429);
        }

        $username = $this->auth->verify($this->bearerToken($request));

        if ($username === null) {
            $limiter->hit($key, self::AUTH_WINDOW_SECONDS);
            usleep(350000);
            throw new AppException('Nao autorizado.', 401);
        }

        $limiter->clear($key);

        return $username;
    }

    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('authorization');

        if ($header === null || !preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }
}

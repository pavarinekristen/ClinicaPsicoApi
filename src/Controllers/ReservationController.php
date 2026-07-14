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
        $aceite = $this->normalizeAceite($request->input('aceite'), $request->ip(), $request->header('user-agent'));

        Response::ok($this->reservations->lock($slotIds, $name, $whatsapp, $plan, $crp, $publicosAtendidos, $abordagem, $request->ip(), $aceite));
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

    /** @return array<string, mixed> */
    private function normalizeAceite(mixed $value, string $ip, ?string $userAgent): array
    {
        if (!is_array($value)) {
            throw new AppException('Aceite dos termos e politica de privacidade e obrigatorio.', 422);
        }

        if (($value['aceite_termos'] ?? null) !== true || ($value['aceite_privacidade'] ?? null) !== true) {
            throw new AppException('Aceite dos termos e politica de privacidade e obrigatorio.', 422);
        }

        $acceptedAt = Validator::requiredString($value['data_hora_aceite'] ?? null, 'aceite.data_hora_aceite', 40);
        try {
            $acceptedAtUtc = (new \DateTimeImmutable($acceptedAt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new AppException('Data de aceite invalida.', 422);
        }

        return [
            'aceite_termos' => true,
            'aceite_privacidade' => true,
            'versao_termos' => Validator::requiredString($value['versao_termos'] ?? null, 'aceite.versao_termos', 40),
            'versao_privacidade' => Validator::requiredString($value['versao_privacidade'] ?? null, 'aceite.versao_privacidade', 40),
            'data_hora_aceite' => $acceptedAtUtc,
            'origem_aceite' => Validator::requiredString($value['origem_aceite'] ?? null, 'aceite.origem_aceite', 80),
            'texto_aceite' => Validator::requiredString($value['texto_aceite'] ?? null, 'aceite.texto_aceite', 800),
            'aceite_user_agent' => is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
            'aceite_ip' => $ip !== '' ? $ip : null,
        ];
    }
}

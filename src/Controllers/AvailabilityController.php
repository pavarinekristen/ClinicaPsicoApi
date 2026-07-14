<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AppException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AvailabilityService;

final class AvailabilityController
{
    public function __construct(private readonly AvailabilityService $availability)
    {
    }

    public function index(Request $request): never
    {
        $salaId = Validator::requiredString($request->query('sala_id'), 'sala_id', 36);
        $date = Validator::dateYmd($request->query('date'), 'date');

        Response::ok([
            'sala_id' => $salaId,
            'date' => $date,
            'slots' => $this->availability->forDay($salaId, $date),
        ]);
    }

    public function range(Request $request): never
    {
        $salaId = Validator::requiredString($request->query('sala_id'), 'sala_id', 36);
        $startDate = Validator::dateYmd($request->query('start_date'), 'start_date');
        $endDate = Validator::dateYmd($request->query('end_date'), 'end_date');

        if ($endDate < $startDate) {
            throw new AppException('Intervalo de datas invalido.', 422);
        }

        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        if ($start->diff($end)->days > 62) {
            throw new AppException('Intervalo maximo de consulta: 62 dias.', 422);
        }

        Response::ok([
            'sala_id' => $salaId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'slots_by_date' => $this->availability->forRange($salaId, $startDate, $endDate),
        ]);
    }
}
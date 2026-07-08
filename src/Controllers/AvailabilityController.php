<?php

declare(strict_types=1);

namespace App\Controllers;

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
}
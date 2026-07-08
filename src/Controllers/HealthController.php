<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class HealthController
{
    public function show(Request $request): never
    {
        Response::ok([
            'service' => 'ClinicaIdeiaApi',
            'status' => 'online',
            'time' => gmdate('c'),
        ]);
    }
}
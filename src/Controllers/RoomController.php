<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\RoomRepository;

final class RoomController
{
    public function __construct(private readonly RoomRepository $rooms)
    {
    }

    public function index(Request $request): never
    {
        Response::ok(['rooms' => $this->rooms->activeRooms()]);
    }
}
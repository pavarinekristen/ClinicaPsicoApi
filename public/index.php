<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AvailabilityController;
use App\Controllers\HealthController;
use App\Controllers\ReservationController;
use App\Controllers\RoomController;
use App\Core\AppException;
use App\Core\Database;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Repositories\RoomRepository;
use App\Repositories\SlotRepository;
use App\Services\AvailabilityService;
use App\Services\ReservationService;

require_once __DIR__ . '/../src/Core/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

$request = Request::capture();
Response::applyCors($request);

if ($request->method() === 'OPTIONS') {
    Response::noContent();
}

$router = new Router(Env::get('API_BASE_PATH', '/api'));

$router->get('/health', [new HealthController(), 'show']);
$router->get('/rooms', static fn (Request $request) => roomController()->index($request));
$router->get('/availability', static fn (Request $request) => availabilityController()->index($request));
$router->post('/reservations/lock', static fn (Request $request) => reservationController()->lock($request));
$router->post('/reservations/confirm', static fn (Request $request) => reservationController()->confirm($request));
$router->get('/admin/reservations', static fn (Request $request) => adminController()->listReservations($request));
$router->get('/admin/reservations/day', static fn (Request $request) => adminController()->reservationsByDay($request));
$router->post('/admin/slots/generate', static fn (Request $request) => adminController()->generateSlots($request));
$router->post('/admin/reservations/confirm', static fn (Request $request) => adminController()->confirmReservation($request));
$router->post('/admin/reservations/confirm-by-id', static fn (Request $request) => adminController()->confirmReservationById($request));
$router->post('/admin/reservations/cancel', static fn (Request $request) => adminController()->cancelReservation($request));
$router->post('/admin/reservations/update', static fn (Request $request) => adminController()->updateReservation($request));
$router->post('/admin/slots/block', static fn (Request $request) => adminController()->blockSlot($request));
$router->post('/admin/slots/unblock', static fn (Request $request) => adminController()->unblockSlot($request));

try {
    $router->dispatch($request);
} catch (AppException $exception) {
    Response::error($exception->getMessage(), $exception->statusCode(), $exception->details());
} catch (Throwable $exception) {
    $debug = Env::get('APP_DEBUG', 'false') === 'true';
    Response::error(
        'Erro interno da API.',
        500,
        $debug ? ['exception' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()] : []
    );
}

function slotRepository(): SlotRepository
{
    static $repository = null;

    if ($repository === null) {
        $repository = new SlotRepository(Database::connect());
    }

    return $repository;
}

function roomController(): RoomController
{
    static $controller = null;

    if ($controller === null) {
        $controller = new RoomController(new RoomRepository(Database::connect()));
    }

    return $controller;
}

function availabilityController(): AvailabilityController
{
    static $controller = null;

    if ($controller === null) {
        $controller = new AvailabilityController(new AvailabilityService(slotRepository()));
    }

    return $controller;
}

function reservationController(): ReservationController
{
    static $controller = null;

    if ($controller === null) {
        $service = new ReservationService(slotRepository(), (int) Env::get('LOCK_TTL_MINUTES', '10'));
        $controller = new ReservationController($service);
    }

    return $controller;
}

function adminController(): AdminController
{
    static $controller = null;

    if ($controller === null) {
        $service = new ReservationService(slotRepository(), (int) Env::get('LOCK_TTL_MINUTES', '10'));
        $controller = new AdminController($service);
    }

    return $controller;
}

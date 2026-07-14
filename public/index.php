<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
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
use App\Repositories\AuditRepository;
use App\Repositories\RoomRepository;
use App\Repositories\SlotRepository;
use App\Services\AuthService;
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
$router->post('/admin/login', static fn (Request $request) => authController()->login($request));
$router->get('/admin/reservations', static fn (Request $request) => adminController()->listReservations($request));
$router->get('/admin/reservations/day', static fn (Request $request) => adminController()->reservationsByDay($request));
$router->get('/admin/reservations/history', static fn (Request $request) => adminController()->history($request));
$router->post('/admin/reservations/delete', static fn (Request $request) => adminController()->deleteReservations($request));
$router->post('/admin/reservations/delete-all', static fn (Request $request) => adminController()->deleteAllHistory($request));
$router->post('/admin/slots/generate', static fn (Request $request) => adminController()->generateSlots($request));
$router->post('/admin/reservations/confirm', static fn (Request $request) => adminController()->confirmReservation($request));
$router->post('/admin/reservations/confirm-by-id', static fn (Request $request) => adminController()->confirmReservationById($request));
$router->post('/admin/reservations/pix-received', static fn (Request $request) => adminController()->markPixReceived($request));
$router->post('/admin/reservations/cancel', static fn (Request $request) => adminController()->cancelReservation($request));
$router->post('/admin/reservations/cancel-slot', static fn (Request $request) => adminController()->cancelReservationSlot($request));
$router->post('/admin/reservations/update', static fn (Request $request) => adminController()->updateReservation($request));
$router->post('/admin/slots/block', static fn (Request $request) => adminController()->blockSlot($request));
$router->post('/admin/slots/unblock', static fn (Request $request) => adminController()->unblockSlot($request));

try {
    $router->dispatch($request);
} catch (AppException $exception) {
    Response::error($exception->getMessage(), $exception->statusCode(), $exception->details());
} catch (Throwable $exception) {
    // Trace completo sempre vai para o log local (fora do webroot), nunca para o cliente...
    @error_log(
        sprintf(
            "[%s] %s in %s:%d\n%s\n",
            gmdate('c'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        ),
        3,
        dirname(__DIR__) . '/storage/logs/app-errors.log'
    );

    // ...e detalhes so aparecem na resposta em ambiente local explicito.
    $isLocalDebug = Env::get('APP_ENV', 'production') === 'local' && Env::get('APP_DEBUG', 'false') === 'true';
    Response::error(
        'Erro interno da API.',
        500,
        $isLocalDebug ? ['exception' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()] : []
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

function authService(): AuthService
{
    static $service = null;

    if ($service === null) {
        $service = new AuthService();
    }

    return $service;
}

function auditRepository(): AuditRepository
{
    static $repository = null;

    if ($repository === null) {
        $repository = new AuditRepository(Database::connect());
    }

    return $repository;
}

function authController(): AuthController
{
    static $controller = null;

    if ($controller === null) {
        $controller = new AuthController(authService(), auditRepository());
    }

    return $controller;
}

function adminController(): AdminController
{
    static $controller = null;

    if ($controller === null) {
        $service = new ReservationService(slotRepository(), (int) Env::get('LOCK_TTL_MINUTES', '10'));
        $controller = new AdminController($service, authService(), auditRepository());
    }

    return $controller;
}

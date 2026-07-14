<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Repositories\SlotRepository;

require_once dirname(__DIR__) . '/src/Core/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

$logFile = dirname(__DIR__) . '/storage/logs/cleanup-expired-locks.log';

try {
    $repository = new SlotRepository(Database::connect());
    $result = $repository->cleanupExpiredLocks();

    $line = sprintf(
        "[%s] expired_reservations=%d released_slots=%d%s",
        gmdate('c'),
        $result['expired_reservations'],
        $result['released_slots'],
        PHP_EOL
    );
    @error_log($line, 3, $logFile);

    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $line = sprintf(
        "[%s] ERROR %s in %s:%d%s%s%s",
        gmdate('c'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        PHP_EOL,
        $exception->getTraceAsString(),
        PHP_EOL
    );
    @error_log($line, 3, $logFile);

    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'cleanup_failed'], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
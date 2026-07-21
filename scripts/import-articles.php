<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Env;
use App\Repositories\ArticleRepository;
use App\Services\ArticleImportService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente via linha de comando.\n");
}

require __DIR__ . '/../src/Core/autoload.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

try {
    $repository = new ArticleRepository(Database::connect());
    $service = new ArticleImportService($repository, dirname(__DIR__) . '/storage/logs/article-import.log');
    $result = $service->import();

    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    @error_log(
        '[' . gmdate('c') . '] cron article import failed: ' . $exception->getMessage() . "\n",
        3,
        dirname(__DIR__) . '/storage/logs/article-import.log'
    );

    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
